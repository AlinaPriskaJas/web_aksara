<?php
// admin/stock.php — Modul Gudang: Stock Opname per kategori (tab dinamis sesuai Excel)

// ====== Export Rekap Transaksi Pemakaian Bulanan (Surat PDF) ======
// Diproses PALING ATAS (sebelum header/sidebar/topbar) karena harus mengirim
// header PDF mentah tanpa ada output HTML apa pun sebelumnya.
if (($_GET['export'] ?? '') === 'rekap_transaksi_pdf') {
    require_once "../config/koneksi.php";
    require_once "../includes/stock_import_helper.php";
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../login.php");
        exit;
    }

    $bulanFilter = trim($_GET['bulan'] ?? date('Y-m')); // format YYYY-MM
    if (!preg_match('/^\d{4}-\d{2}$/', $bulanFilter)) {
        $bulanFilter = date('Y-m');
    }
    $idKategoriFilter = intval($_GET['id_kategori'] ?? 0);

    $namaBulanIndoExport = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];
    [$thnFilter, $blnFilter] = explode('-', $bulanFilter);
    $labelBulanExport = $namaBulanIndoExport[(int) $blnFilter] . ' ' . $thnFilter;

    $sqlExport = "SELECT ms.tanggal, ms.jumlah, ms.pemakai, ms.keterangan,
            gs.kode_barang, gs.nama_barang, gs.satuan, kbg.nama_kategori
        FROM Mutasi_Stok ms
        JOIN Gudang_Stok gs ON ms.barang_id = gs.id
        JOIN Jenis_Barang_Gudang jbg ON gs.id_jenis = jbg.id_jenis
        JOIN Kategori_Barang_Gudang kbg ON jbg.id_kategori = kbg.id_kategori
        WHERE ms.jenis_mutasi = 'Keluar' AND DATE_FORMAT(ms.tanggal, '%Y-%m') = :bulan";
    $paramsExport = ['bulan' => $bulanFilter];
    if ($idKategoriFilter > 0) {
        $sqlExport .= " AND kbg.id_kategori = :id_kategori";
        $paramsExport['id_kategori'] = $idKategoriFilter;
    }
    $sqlExport .= " ORDER BY ms.tanggal ASC, ms.id ASC";

    $stmtExport = $conn->prepare($sqlExport);
    $stmtExport->execute($paramsExport);
    $dataExport = $stmtExport->fetchAll();

    $namaKategoriLabelExport = 'Semua Kategori';
    if ($idKategoriFilter > 0) {
        $stmtKatLabel = $conn->prepare("SELECT nama_kategori FROM Kategori_Barang_Gudang WHERE id_kategori = :id");
        $stmtKatLabel->execute(['id' => $idKategoriFilter]);
        $katRowExport = $stmtKatLabel->fetch();
        if ($katRowExport) {
            $namaKategoriLabelExport = $katRowExport['nama_kategori'];
        }
    }

    $headersPdf = ['No', 'Tanggal', 'Kode', 'Nama Barang', 'Kategori', 'Volume', 'Pemakai', 'Keterangan'];
    $colCharsPdf = [3, 10, 8, 30, 14, 10, 18, 30];
    $rowsPdf = [];
    $noExport = 1;
    $totalQtyExport = 0;
    foreach ($dataExport as $d) {
        $rowsPdf[] = [
            (string) $noExport++,
            date('d-m-Y', strtotime($d['tanggal'])),
            (string) $d['kode_barang'],
            (string) $d['nama_barang'],
            (string) $d['nama_kategori'],
            $d['jumlah'] . ' ' . $d['satuan'],
            (string) ($d['pemakai'] ?: '-'),
            (string) ($d['keterangan'] ?: '-'),
        ];
        $totalQtyExport += (int) $d['jumlah'];
    }
    if (empty($rowsPdf)) {
        $rowsPdf[] = ['-', 'Tidak ada transaksi pemakaian pada periode ini.', '', '', '', '', '', ''];
    }

    $pdfBytesExport = buatPdfSederhanaTable(
        'Rekap Transaksi Pemakaian Barang Gudang',
        'Periode: ' . $labelBulanExport . '  |  Kategori: ' . $namaKategoriLabelExport . '  |  Total Volume: ' . $totalQtyExport . '  |  Dicetak: ' . date('d-m-Y H:i'),
        $headersPdf,
        $colCharsPdf,
        $rowsPdf
    );

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Rekap-Transaksi-' . $bulanFilter . '.pdf"');
    header('Content-Length: ' . strlen($pdfBytesExport));
    echo $pdfBytesExport;
    exit;
}

// ====== Export Rekap Tahunan Keuangan Gudang (Surat PDF) — pembelian & pemakaian ======
if (($_GET['export'] ?? '') === 'rekap_tahunan_pdf') {
    require_once "../config/koneksi.php";
    require_once "../includes/stock_import_helper.php";
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../login.php");
        exit;
    }

    $tahunFilter = intval($_GET['tahun'] ?? date('Y'));
    if ($tahunFilter < 2000 || $tahunFilter > 2100) {
        $tahunFilter = (int) date('Y');
    }
    $idKategoriFilterThn = intval($_GET['id_kategori'] ?? 0);

    $sqlExportThn = "SELECT gs.kode_barang, gs.nama_barang, gs.satuan, kbg.nama_kategori,
            SUM(CASE WHEN ms.jenis_mutasi = 'Masuk' THEN ms.jumlah ELSE 0 END) AS qty_masuk,
            SUM(CASE WHEN ms.jenis_mutasi = 'Masuk' THEN ms.jumlah * COALESCE(gs.harga_satuan, 0) ELSE 0 END) AS nilai_masuk,
            SUM(CASE WHEN ms.jenis_mutasi = 'Keluar' THEN ms.jumlah ELSE 0 END) AS qty_keluar,
            SUM(CASE WHEN ms.jenis_mutasi = 'Keluar' THEN ms.jumlah * COALESCE(gs.harga_satuan, 0) ELSE 0 END) AS nilai_keluar
        FROM Mutasi_Stok ms
        JOIN Gudang_Stok gs ON ms.barang_id = gs.id
        JOIN Jenis_Barang_Gudang jbg ON gs.id_jenis = jbg.id_jenis
        JOIN Kategori_Barang_Gudang kbg ON jbg.id_kategori = kbg.id_kategori
        WHERE YEAR(ms.tanggal) = :tahun AND ms.jenis_mutasi IN ('Masuk','Keluar')";
    $paramsExportThn = ['tahun' => $tahunFilter];
    if ($idKategoriFilterThn > 0) {
        $sqlExportThn .= " AND kbg.id_kategori = :id_kategori";
        $paramsExportThn['id_kategori'] = $idKategoriFilterThn;
    }
    $sqlExportThn .= " GROUP BY gs.id
        HAVING qty_masuk > 0 OR qty_keluar > 0
        ORDER BY CAST(SUBSTRING_INDEX(gs.kode_barang, '.', 1) AS UNSIGNED) ASC,
                 CAST(SUBSTRING_INDEX(gs.kode_barang, '.', -1) AS UNSIGNED) ASC";

    $stmtExportThn = $conn->prepare($sqlExportThn);
    $stmtExportThn->execute($paramsExportThn);
    $dataExportThn = $stmtExportThn->fetchAll();

    $namaKategoriLabelThn = 'Semua Kategori';
    if ($idKategoriFilterThn > 0) {
        $stmtKatLabelThn = $conn->prepare("SELECT nama_kategori FROM Kategori_Barang_Gudang WHERE id_kategori = :id");
        $stmtKatLabelThn->execute(['id' => $idKategoriFilterThn]);
        $katRowThn = $stmtKatLabelThn->fetch();
        if ($katRowThn) {
            $namaKategoriLabelThn = $katRowThn['nama_kategori'];
        }
    }

    $headersPdfThn = ['No', 'Kode', 'Nama Barang', 'Kategori', 'Qty Dibeli', 'Nilai Dibeli (Rp)', 'Qty Dipakai', 'Nilai Dipakai (Rp)'];
    $colCharsThn = [3, 8, 26, 12, 10, 16, 10, 16];
    $rowsPdfThn = [];
    $noThn = 1;
    $totalNilaiMasukThn = 0;
    $totalNilaiKeluarThn = 0;
    foreach ($dataExportThn as $d) {
        $rowsPdfThn[] = [
            (string) $noThn++,
            (string) $d['kode_barang'],
            (string) $d['nama_barang'],
            (string) $d['nama_kategori'],
            $d['qty_masuk'] . ' ' . $d['satuan'],
            number_format((float) $d['nilai_masuk'], 0, ',', '.'),
            $d['qty_keluar'] . ' ' . $d['satuan'],
            number_format((float) $d['nilai_keluar'], 0, ',', '.'),
        ];
        $totalNilaiMasukThn += (float) $d['nilai_masuk'];
        $totalNilaiKeluarThn += (float) $d['nilai_keluar'];
    }
    if (empty($rowsPdfThn)) {
        $rowsPdfThn[] = ['-', 'Tidak ada transaksi pembelian/pemakaian pada tahun ini.', '', '', '', '', '', ''];
    }

    $pdfBytesThn = buatPdfSederhanaTable(
        'Rekap Tahunan Keuangan Gudang',
        'Tahun: ' . $tahunFilter . '  |  Kategori: ' . $namaKategoriLabelThn . '  |  Total Nilai Dibeli: Rp ' . number_format($totalNilaiMasukThn, 0, ',', '.') . '  |  Total Nilai Dipakai: Rp ' . number_format($totalNilaiKeluarThn, 0, ',', '.') . '  |  Dicetak: ' . date('d-m-Y H:i'),
        $headersPdfThn,
        $colCharsThn,
        $rowsPdfThn
    );

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Rekap-Tahunan-Keuangan-' . $tahunFilter . '.pdf"');
    header('Content-Length: ' . strlen($pdfBytesThn));
    echo $pdfBytesThn;
    exit;
}

// ====== Export Rekap Anggaran Gudang per Kategori (Surat PDF) — gaya RAB ======
if (($_GET['export'] ?? '') === 'rekap_anggaran_kategori_pdf') {
    require_once "../config/koneksi.php";
    require_once "../includes/stock_import_helper.php";
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../login.php");
        exit;
    }

    $tahunAnggaranExport = intval($_GET['tahun_anggaran'] ?? date('Y'));
    if ($tahunAnggaranExport < 2000 || $tahunAnggaranExport > 2100) {
        $tahunAnggaranExport = (int) date('Y');
    }

    $kategorisExport = $conn->query("SELECT * FROM Kategori_Barang_Gudang ORDER BY id_kategori ASC")->fetchAll();

    $stmtItemsExport = $conn->prepare("
        SELECT gs.id, gs.kode_barang, gs.nama_barang, gs.satuan, gs.harga_satuan, kbg.id_kategori,
            COALESCE(SUM(CASE WHEN ms.jenis_mutasi = 'Masuk' AND YEAR(ms.tanggal) = :tahun THEN ms.jumlah ELSE 0 END), 0) AS vol
        FROM Gudang_Stok gs
        JOIN Jenis_Barang_Gudang jbg ON gs.id_jenis = jbg.id_jenis
        JOIN Kategori_Barang_Gudang kbg ON jbg.id_kategori = kbg.id_kategori
        LEFT JOIN Mutasi_Stok ms ON ms.barang_id = gs.id
        GROUP BY gs.id
        ORDER BY kbg.id_kategori ASC,
                 CAST(SUBSTRING_INDEX(gs.kode_barang, '.', 1) AS UNSIGNED) ASC,
                 CAST(SUBSTRING_INDEX(gs.kode_barang, '.', -1) AS UNSIGNED) ASC
    ");
    $stmtItemsExport->execute(['tahun' => $tahunAnggaranExport]);
    $itemsExport = $stmtItemsExport->fetchAll();

    $itemsByKategoriExport = [];
    foreach ($kategorisExport as $kat) {
        $itemsByKategoriExport[$kat['id_kategori']] = [];
    }
    foreach ($itemsExport as $it) {
        $itemsByKategoriExport[$it['id_kategori']][] = $it;
    }

    $headersAnggaranPdf = ['Kode', 'No', 'Uraian', 'Vol', 'Sat', 'Harga Satuan', 'Jumlah'];
    $colCharsAnggaranPdf = [7, 10, 32, 6, 7, 15, 18];
    $rowsAnggaranPdf = [];
    $noKategoriPdf = 0;
    $grandTotalAnggaranPdf = 0;
    foreach ($kategorisExport as $kat) {
        $noKategoriPdf++;
        $rowsAnggaranPdf[] = ['0.' . $noKategoriPdf, '1.' . $noKategoriPdf, $kat['nama_kategori'], '', '', '', ''];
        $subtotalPdf = 0;
        foreach ($itemsByKategoriExport[$kat['id_kategori']] as $it) {
            $vol = (int) $it['vol'];
            $harga = $it['harga_satuan'] !== null ? (float) $it['harga_satuan'] : 0;
            $nilai = $vol * $harga;
            $rowsAnggaranPdf[] = [
                '',
                (string) $it['kode_barang'],
                (string) $it['nama_barang'],
                (string) $vol,
                (string) $it['satuan'],
                'Rp ' . number_format($harga, 0, ',', '.'),
                'Rp ' . number_format($nilai, 0, ',', '.'),
            ];
            $subtotalPdf += $nilai;
        }
        $rowsAnggaranPdf[] = ['', '', '', '', '', 'Subtotal ' . $kat['nama_kategori'], 'Rp ' . number_format($subtotalPdf, 0, ',', '.')];
        $grandTotalAnggaranPdf += $subtotalPdf;
    }
    if (empty($rowsAnggaranPdf)) {
        $rowsAnggaranPdf[] = ['-', 'Belum ada kategori/barang gudang untuk direkap.', '', '', '', '', ''];
    }

    $pdfBytesAnggaran = buatPdfSederhanaTable(
        'Rekap Anggaran Gudang per Kategori',
        'Tahun: ' . $tahunAnggaranExport . '  |  Total: Rp ' . number_format($grandTotalAnggaranPdf, 0, ',', '.') . '  |  Dicetak: ' . date('d-m-Y H:i'),
        $headersAnggaranPdf,
        $colCharsAnggaranPdf,
        $rowsAnggaranPdf
    );

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Rekap-Anggaran-Kategori-' . $tahunAnggaranExport . '.pdf"');
    header('Content-Length: ' . strlen($pdfBytesAnggaran));
    echo $pdfBytesAnggaran;
    exit;
}

$page_title = "Laporan Stock Opname";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
require_once "../config/koneksi.php";
require_once "../includes/stock_import_helper.php";

// Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$success_msg = "";
$error_msg = "";
$import_result = null;
$current_user_id = $_SESSION['user_id'];
$active_tab = 'tabGudang';

function stockKategoriIcon(string $nama): string
{
    $nama = strtolower($nama);
    if (strpos($nama, 'atk') !== false)
        return 'bi-pencil-fill';
    if (strpos($nama, 'aak3') !== false || strpos($nama, 'ahli') !== false)
        return 'bi-tools';
    if (strpos($nama, 'konsumsi') !== false)
        return 'bi-cup-straw';
    if (strpos($nama, 'bersih') !== false)
        return 'bi-droplet-fill';
    return 'bi-box-seam';
}

// ============================== POST HANDLERS ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- 1. Import CSV/Excel Stock Opname (pilih kategori dulu, lalu file) ----
    if (isset($_POST['action']) && $_POST['action'] === 'import_stok') {
        $active_tab = 'tabGudang';
        $id_kategori = intval($_POST['id_kategori_import'] ?? 0);

        if ($id_kategori <= 0 || empty($_FILES['file_import']['name'])) {
            $error_msg = "Pilih kategori dan file (.csv / .xlsx) terlebih dahulu!";
        } else {
            try {
                $stmtKat = $conn->prepare("SELECT nama_kategori FROM Kategori_Barang_Gudang WHERE id_kategori = :id");
                $stmtKat->execute(['id' => $id_kategori]);
                $kat = $stmtKat->fetch();
                if (!$kat) {
                    throw new Exception("Kategori tidak ditemukan.");
                }

                $ext = strtolower(pathinfo($_FILES['file_import']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['csv', 'xlsx'])) {
                    throw new Exception("Hanya file .csv atau .xlsx yang didukung.");
                }
                if (!is_uploaded_file($_FILES['file_import']['tmp_name'])) {
                    throw new Exception("Upload file gagal / mencurigakan.");
                }

                $import_result = processStockImport(
                    $conn,
                    $_FILES['file_import']['tmp_name'],
                    $_FILES['file_import']['name'],
                    $id_kategori,
                    $kat['nama_kategori'],
                    $current_user_id
                );

                catatAudit(
                    $conn,
                    'Gudang',
                    'Import Stok',
                    "Import stok kategori {$kat['nama_kategori']} dari file {$_FILES['file_import']['name']}: {$import_result['berhasil']} berhasil, {$import_result['duplikat']} diperbarui, {$import_result['gagal']} gagal",
                    null,
                    $import_result
                );

                $success_msg = "Import selesai: {$import_result['berhasil']} barang baru, {$import_result['duplikat']} diperbarui, {$import_result['gagal']} gagal dari total {$import_result['total_baris']} baris.";
            } catch (Exception $e) {
                $error_msg = "Gagal import: " . $e->getMessage();
            }
        }
    }

    // ---- 2. Tambah Barang Baru ----
    if (isset($_POST['action']) && $_POST['action'] === 'tambah_barang') {
        $active_tab = 'tabGudang';
        $nama_barang = trim($_POST['nama_barang']);
        $nama_kategori = trim($_POST['kategori_pilih'] ?? '');
        $satuan = trim($_POST['satuan']);
        $stok_awal = intval($_POST['stok_awal']);
        // Stok Minimum sudah tidak ada lagi di form input (poin 10).
        $stok_minimum = null;
        $lokasi_rak = trim($_POST['lokasi_rak']);
        // Harga Satuan tidak lagi diisi di sini — diatur belakangan lewat tab Keuangan
        // (menu "Atur Harga"), supaya form Tambah Barang tetap ringkas.
        $harga_satuan = null;
        $jenis_pakai = ($_POST['jenis_pakai'] ?? '') === 'Tidak Habis Pakai' ? 'Tidak Habis Pakai' : 'Habis Pakai';
        // Kode Barang TIDAK diisi manual lagi — dibuat otomatis sesuai kategori
        // (1.x = ATK, 2.x = AAK3, 3.x = Konsumsi, 4.x = Kebersihan), meniru
        // penomoran di file Excel Stock Opname perusahaan.
        $kode_barang = '';

        if (empty($nama_barang) || empty($nama_kategori) || empty($satuan)) {
            $error_msg = "Semua field wajib (Nama Barang, Kategori, Satuan) harus diisi!";
        } else {
            try {
                $conn->beginTransaction();

                $stmtCekKat = $conn->prepare("SELECT id_kategori FROM Kategori_Barang_Gudang WHERE nama_kategori = :nama");
                $stmtCekKat->execute(['nama' => $nama_kategori]);
                $kat = $stmtCekKat->fetch();

                if ($kat) {
                    $id_kategori = $kat['id_kategori'];
                } else {
                    $stmtInsKat = $conn->prepare("INSERT INTO Kategori_Barang_Gudang (nama_kategori) VALUES (:nama)");
                    $stmtInsKat->execute(['nama' => $nama_kategori]);
                    $id_kategori = $conn->lastInsertId();
                }

                $stmtCekJenis = $conn->prepare("SELECT id_jenis FROM Jenis_Barang_Gudang WHERE id_kategori = :id_kategori AND nama_jenis = :nama");
                $stmtCekJenis->execute(['id_kategori' => $id_kategori, 'nama' => $nama_kategori]);
                $jenis = $stmtCekJenis->fetch();

                if ($jenis) {
                    $id_jenis = $jenis['id_jenis'];
                } else {
                    $stmtInsJenis = $conn->prepare("INSERT INTO Jenis_Barang_Gudang (id_kategori, nama_jenis) VALUES (:id_kategori, :nama)");
                    $stmtInsJenis->execute(['id_kategori' => $id_kategori, 'nama' => $nama_kategori]);
                    $id_jenis = $conn->lastInsertId();
                }

                // Generate kode barang otomatis (format "1.x/2.x/3.x/4.x" sesuai kategori)
                $kode_barang = stockGenerateKodeBarang($conn, $id_kategori, $nama_kategori);

                $stmtInsBarang = $conn->prepare("INSERT INTO Gudang_Stok
                    (kode_barang, nama_barang, id_jenis, satuan, stok_sistem, stok_awal, tgl_opname_awal, lokasi_rak, stok_minimum, harga_satuan, jenis_pakai)
                    VALUES (:kode, :nama, :id_jenis, :satuan, :stok, :stok_awal, :tgl, :rak, :min, :harga, :jenis_pakai)");
                $stmtInsBarang->execute([
                    'kode' => $kode_barang,
                    'nama' => $nama_barang,
                    'id_jenis' => $id_jenis,
                    'satuan' => $satuan,
                    'stok' => $stok_awal,
                    'stok_awal' => $stok_awal,
                    'tgl' => date('Y-m-d'),
                    'rak' => $lokasi_rak,
                    'min' => $stok_minimum,
                    'harga' => $harga_satuan,
                    'jenis_pakai' => $jenis_pakai,
                ]);

                $barang_baru_id = $conn->lastInsertId();

                if ($stok_awal > 0) {
                    $stmtMutAwal = $conn->prepare("INSERT INTO Mutasi_Stok (barang_id, jenis_mutasi, jumlah, tanggal, keterangan, dibuat_oleh) VALUES (:barang_id, 'Penyesuaian Opname', :jumlah, :tanggal, 'Stok awal saat penambahan barang baru', :user_id)");
                    $stmtMutAwal->execute([
                        'barang_id' => $barang_baru_id,
                        'jumlah' => $stok_awal,
                        'tanggal' => date('Y-m-d'),
                        'user_id' => $current_user_id
                    ]);
                }

                $conn->commit();
                catatAudit(
                    $conn,
                    'Gudang',
                    'Tambah Barang',
                    "Menambahkan barang {$kode_barang} - {$nama_barang} (stok awal {$stok_awal})",
                    null,
                    ['kode_barang' => $kode_barang, 'nama_barang' => $nama_barang, 'stok_awal' => $stok_awal, 'lokasi_rak' => $lokasi_rak]
                );
                $success_msg = "Barang baru '$nama_barang' berhasil ditambahkan ke gudang!";
                $active_tab = 'tabGudang';
            } catch (Exception $e) {
                $conn->rollBack();
                $error_msg = "Gagal menambah barang: " . $e->getMessage();
            }
        }
    }

    // ---- 3. Form Penggunaan Barang (Pemakaian / Keluar) ----
    if (isset($_POST['action']) && $_POST['action'] === 'pemakaian') {
        $active_tab = 'tabTransaksi';
        $barang_id = intval($_POST['barang_id']);
        $jumlah = intval($_POST['jumlah']);
        $pemakai = trim($_POST['pemakai'] ?? '');
        $keterangan = trim($_POST['keterangan'] ?? '');
        $tanggal = !empty($_POST['tanggal']) ? $_POST['tanggal'] : date('Y-m-d');

        if (empty($barang_id) || $jumlah <= 0 || empty($pemakai)) {
            $error_msg = "Pilih barang, jumlah pemakaian, dan nama pemakai wajib diisi!";
        } else {
            try {
                $conn->beginTransaction();

                $stmtMut = $conn->prepare("INSERT INTO Mutasi_Stok (barang_id, jenis_mutasi, jumlah, pemakai, tanggal, keterangan, dibuat_oleh)
                    VALUES (:barang_id, 'Keluar', :jumlah, :pemakai, :tanggal, :ket, :user_id)");
                $stmtMut->execute([
                    'barang_id' => $barang_id,
                    'jumlah' => $jumlah,
                    'pemakai' => $pemakai,
                    'tanggal' => $tanggal,
                    'ket' => $keterangan,
                    'user_id' => $current_user_id
                ]);

                $stmtUpd = $conn->prepare("UPDATE Gudang_Stok SET stok_sistem = stok_sistem - :jumlah WHERE id = :barang_id");
                $stmtUpd->execute(['jumlah' => $jumlah, 'barang_id' => $barang_id]);

                $conn->commit();
                catatAudit(
                    $conn,
                    'Gudang',
                    'Pemakaian Barang',
                    "Pemakaian {$jumlah} unit barang #{$barang_id} oleh {$pemakai}",
                    null,
                    ['jumlah' => $jumlah, 'pemakai' => $pemakai, 'keterangan' => $keterangan]
                );
                $success_msg = "Pemakaian barang berhasil dicatat! Sisa stok telah diperbarui otomatis.";
            } catch (Exception $e) {
                $conn->rollBack();
                $error_msg = "Gagal mencatat pemakaian: " . $e->getMessage();
            }
        }
    }

    // ---- 4. Catat Barang Masuk ----
    if (isset($_POST['action']) && $_POST['action'] === 'barang_masuk') {
        $active_tab = 'tabBarangMasuk';
        $barang_id = intval($_POST['barang_id']);
        $jumlah = intval($_POST['jumlah']);
        $keterangan = trim($_POST['keterangan'] ?? '');
        $tanggal = !empty($_POST['tanggal']) ? $_POST['tanggal'] : date('Y-m-d');

        if (empty($barang_id) || $jumlah <= 0) {
            $error_msg = "Pilih barang dan jumlah barang masuk yang valid!";
        } else {
            try {
                $conn->beginTransaction();

                $stmtMut = $conn->prepare("INSERT INTO Mutasi_Stok (barang_id, jenis_mutasi, jumlah, tanggal, keterangan, dibuat_oleh)
                    VALUES (:barang_id, 'Masuk', :jumlah, :tanggal, :ket, :user_id)");
                $stmtMut->execute([
                    'barang_id' => $barang_id,
                    'jumlah' => $jumlah,
                    'tanggal' => $tanggal,
                    'ket' => $keterangan,
                    'user_id' => $current_user_id
                ]);

                // Barang masuk (pembelian bulan berjalan) menambah stok_sistem SEKALIGUS
                // stok_awal, supaya Sisa Stok (= Stok Awal - Pemakaian) tetap konsisten
                // dan penambahan langsung terlihat di kolom "Stok Awal" tab kategorinya.
                $stmtUpd = $conn->prepare("UPDATE Gudang_Stok SET stok_sistem = stok_sistem + :jumlah, stok_awal = stok_awal + :jumlah WHERE id = :barang_id");
                $stmtUpd->execute(['jumlah' => $jumlah, 'barang_id' => $barang_id]);

                $conn->commit();
                catatAudit(
                    $conn,
                    'Gudang',
                    'Barang Masuk',
                    "Barang masuk {$jumlah} unit untuk barang #{$barang_id}",
                    null,
                    ['jumlah' => $jumlah, 'keterangan' => $keterangan]
                );
                $success_msg = "Barang masuk berhasil dicatat! Stok telah diperbarui.";
            } catch (Exception $e) {
                $conn->rollBack();
                $error_msg = "Gagal mencatat barang masuk: " . $e->getMessage();
            }
        }
    }

    // ---- 5. Edit Barang (satuan, rak, jenis pakai) ----
    if (isset($_POST['action']) && $_POST['action'] === 'edit_barang') {
        $active_tab = 'tabGudang';
        $barang_id = intval($_POST['barang_id']);
        $satuan = trim($_POST['satuan']);
        $lokasi_rak = trim($_POST['lokasi_rak']);
        $jenis_pakai = ($_POST['jenis_pakai'] ?? '') === 'Tidak Habis Pakai' ? 'Tidak Habis Pakai' : 'Habis Pakai';

        try {
            $stmt = $conn->prepare("UPDATE Gudang_Stok SET satuan = :satuan, lokasi_rak = :rak, jenis_pakai = :jenis_pakai WHERE id = :id");
            $stmt->execute([
                'satuan' => $satuan,
                'rak' => $lokasi_rak,
                'jenis_pakai' => $jenis_pakai,
                'id' => $barang_id,
            ]);
            catatAudit($conn, 'Gudang', 'Edit Barang', "Mengubah data barang #{$barang_id}", null, $_POST);
            $success_msg = "Data barang berhasil diperbarui.";
        } catch (Exception $e) {
            $error_msg = "Gagal memperbarui barang: " . $e->getMessage();
        }
    }

    // ---- 6. Atur Harga Satuan (khusus dari tab Keuangan) ----
    if (isset($_POST['action']) && $_POST['action'] === 'edit_harga') {
        $active_tab = 'tabKeuangan';
        $barang_id = intval($_POST['barang_id']);
        $harga_satuan = (!empty($_POST['harga_satuan'])) ? floatval($_POST['harga_satuan']) : null;

        try {
            $stmt = $conn->prepare("UPDATE Gudang_Stok SET harga_satuan = :harga WHERE id = :id");
            $stmt->execute(['harga' => $harga_satuan, 'id' => $barang_id]);
            catatAudit($conn, 'Gudang', 'Atur Harga Satuan', "Mengubah harga satuan barang #{$barang_id}", null, ['harga_satuan' => $harga_satuan]);
            $success_msg = "Harga satuan berhasil diperbarui.";
        } catch (Exception $e) {
            $error_msg = "Gagal memperbarui harga satuan: " . $e->getMessage();
        }
    }
}

// ============================== FETCH DATA ==============================

// Semua kategori (dipakai untuk filter & dropdown, BUKAN tab terpisah lagi — lihat poin 6)
$kategoris = $conn->query("SELECT * FROM Kategori_Barang_Gudang ORDER BY id_kategori ASC")->fetchAll();

// Semua item gudang DIGABUNG jadi satu list (tab ATK/AAK3/Konsumsi/Kebersihan digabung
// jadi satu tab "Gudang Barang", filter kategori & jenis pakai dilakukan di JS/tampilan),
// lengkap dengan pemakaian yang dihitung otomatis dari Mutasi_Stok.
$semuaItems = $conn->query("
    SELECT gs.*, jbg.nama_jenis, kbg.id_kategori, kbg.nama_kategori,
        (SELECT COALESCE(SUM(ms.jumlah), 0) FROM Mutasi_Stok ms
            WHERE ms.barang_id = gs.id AND ms.jenis_mutasi = 'Keluar'
            AND ms.tanggal >= COALESCE(gs.tgl_opname_awal, '1970-01-01')) AS pemakaian
    FROM Gudang_Stok gs
    JOIN Jenis_Barang_Gudang jbg ON gs.id_jenis = jbg.id_jenis
    JOIN Kategori_Barang_Gudang kbg ON jbg.id_kategori = kbg.id_kategori
    ORDER BY kbg.id_kategori ASC,
             CAST(SUBSTRING_INDEX(gs.kode_barang, '.', 1) AS UNSIGNED) ASC,
             CAST(SUBSTRING_INDEX(gs.kode_barang, '.', -1) AS UNSIGNED) ASC
")->fetchAll();

// Dikelompokkan ulang per kategori (dipakai untuk hitung Ringkasan Keuangan per kategori)
$itemsByKategori = [];
foreach ($kategoris as $kat) {
    $itemsByKategori[$kat['id_kategori']] = [];
}
foreach ($semuaItems as $it) {
    $itemsByKategori[$it['id_kategori']][] = $it;
}

// Semua barang (untuk select di modal Barang Masuk, dikelompokkan per kategori)
$semuaBarang = $conn->query("
    SELECT gs.id, gs.kode_barang, gs.nama_barang, gs.satuan, gs.stok_sistem, kbg.nama_kategori
    FROM Gudang_Stok gs
    JOIN Jenis_Barang_Gudang jbg ON gs.id_jenis = jbg.id_jenis
    JOIN Kategori_Barang_Gudang kbg ON jbg.id_kategori = kbg.id_kategori
    ORDER BY kbg.id_kategori ASC,
             CAST(SUBSTRING_INDEX(gs.kode_barang, '.', 1) AS UNSIGNED) ASC,
             CAST(SUBSTRING_INDEX(gs.kode_barang, '.', -1) AS UNSIGNED) ASC
")->fetchAll();

// Log Barang Masuk
$barangMasukList = $conn->query("
    SELECT ms.*, gs.nama_barang, gs.kode_barang, gs.satuan, kbg.nama_kategori, u.nama_lengkap AS operator
    FROM Mutasi_Stok ms
    JOIN Gudang_Stok gs ON ms.barang_id = gs.id
    JOIN Jenis_Barang_Gudang jbg ON gs.id_jenis = jbg.id_jenis
    JOIN Kategori_Barang_Gudang kbg ON jbg.id_kategori = kbg.id_kategori
    JOIN Users u ON ms.dibuat_oleh = u.id
    WHERE ms.jenis_mutasi = 'Masuk'
    ORDER BY ms.tanggal DESC, ms.id DESC LIMIT 200
")->fetchAll();

// Log Transaksi (Pemakaian / Keluar)
$transaksiList = $conn->query("
    SELECT ms.*, gs.nama_barang, gs.kode_barang, gs.satuan, kbg.nama_kategori, u.nama_lengkap AS operator
    FROM Mutasi_Stok ms
    JOIN Gudang_Stok gs ON ms.barang_id = gs.id
    JOIN Jenis_Barang_Gudang jbg ON gs.id_jenis = jbg.id_jenis
    JOIN Kategori_Barang_Gudang kbg ON jbg.id_kategori = kbg.id_kategori
    JOIN Users u ON ms.dibuat_oleh = u.id
    WHERE ms.jenis_mutasi = 'Keluar'
    ORDER BY ms.tanggal DESC, ms.id DESC LIMIT 200
")->fetchAll();

// Ringkasan Keuangan / Nilai Stok per kategori (dari harga_satuan x qty)
$keuangan = [];
$grandTotal = ['stok_awal' => 0, 'pemakaian' => 0, 'sisa' => 0, 'item' => 0];
foreach ($kategoris as $kat) {
    $rowsKat = $itemsByKategori[$kat['id_kategori']];
    $nilaiAwal = 0;
    $nilaiPakai = 0;
    $nilaiSisa = 0;
    foreach ($rowsKat as $it) {
        $harga = $it['harga_satuan'] !== null ? (float) $it['harga_satuan'] : 0;
        $nilaiAwal += $harga * (int) $it['stok_awal'];
        $nilaiPakai += $harga * (int) $it['pemakaian'];
        $nilaiSisa += $harga * (int) $it['stok_sistem'];
    }
    $keuangan[$kat['id_kategori']] = [
        'nama' => $kat['nama_kategori'],
        'jumlah_item' => count($rowsKat),
        'nilai_stok_awal' => $nilaiAwal,
        'nilai_pemakaian' => $nilaiPakai,
        'nilai_sisa_stok' => $nilaiSisa,
    ];
    $grandTotal['item'] += count($rowsKat);
    $grandTotal['stok_awal'] += $nilaiAwal;
    $grandTotal['pemakaian'] += $nilaiPakai;
    $grandTotal['sisa'] += $nilaiSisa;
}

// ====== Rekap Anggaran/Keuangan Tahunan per Kategori (gaya RAB: kategori > item > subtotal > TOTAL) ======
// Sumber: pembelian aktual (Mutasi_Stok jenis 'Masuk') pada tahun terpilih x harga_satuan saat ini,
// dikelompokkan per kategori barang gudang yang ada di sistem.
$tahunAnggaran = intval($_GET['tahun_anggaran'] ?? date('Y'));
if ($tahunAnggaran < 2000 || $tahunAnggaran > 2100) {
    $tahunAnggaran = (int) date('Y');
}

$stmtBelianTahun = $conn->prepare("
    SELECT barang_id, COALESCE(SUM(jumlah), 0) AS vol
    FROM Mutasi_Stok
    WHERE jenis_mutasi = 'Masuk' AND YEAR(tanggal) = :tahun
    GROUP BY barang_id
");
$stmtBelianTahun->execute(['tahun' => $tahunAnggaran]);
$volBelianPerBarang = [];
foreach ($stmtBelianTahun->fetchAll() as $row) {
    $volBelianPerBarang[$row['barang_id']] = (int) $row['vol'];
}

$rekapAnggaran = [];
$grandTotalAnggaran = 0;
$noKategoriAnggaran = 0;
foreach ($kategoris as $kat) {
    $noKategoriAnggaran++;
    $itemsRekap = [];
    $subtotalKategori = 0;
    foreach ($itemsByKategori[$kat['id_kategori']] as $it) {
        $vol = $volBelianPerBarang[$it['id']] ?? 0;
        $harga = $it['harga_satuan'] !== null ? (float) $it['harga_satuan'] : 0;
        $nilai = $vol * $harga;
        $itemsRekap[] = [
            'id' => $it['id'],
            'kode' => $it['kode_barang'],
            'nama' => $it['nama_barang'],
            'vol' => $vol,
            'satuan' => $it['satuan'],
            'harga' => $harga,
            'nilai' => $nilai,
        ];
        $subtotalKategori += $nilai;
    }
    $rekapAnggaran[] = [
        'no' => $noKategoriAnggaran,
        'nama' => $kat['nama_kategori'],
        'items' => $itemsRekap,
        'subtotal' => $subtotalKategori,
    ];
    $grandTotalAnggaran += $subtotalKategori;
}

// Tahun-tahun yang tersedia untuk dropdown filter Rekap Anggaran (dari histori Mutasi_Stok + tahun berjalan)
$tahunAnggaranTersedia = $conn->query("SELECT DISTINCT YEAR(tanggal) AS thn FROM Mutasi_Stok ORDER BY thn DESC")->fetchAll(PDO::FETCH_COLUMN);
$tahunAnggaranTersedia = array_unique(array_merge(array_map('intval', $tahunAnggaranTersedia), [(int) date('Y')]));
rsort($tahunAnggaranTersedia);

// Rekap Tahunan Keuangan Gudang: gabungan Barang Masuk (pembelian) & Transaksi (pemakaian)
// dikelompokkan per TAHUN, dari nilai (jumlah x harga_satuan).
$rekapTahunanRows = $conn->query("
    SELECT YEAR(ms.tanggal) AS tahun,
        SUM(CASE WHEN ms.jenis_mutasi = 'Masuk' THEN 1 ELSE 0 END) AS jml_transaksi_masuk,
        SUM(CASE WHEN ms.jenis_mutasi = 'Masuk' THEN ms.jumlah ELSE 0 END) AS qty_masuk,
        SUM(CASE WHEN ms.jenis_mutasi = 'Masuk' THEN ms.jumlah * COALESCE(gs.harga_satuan, 0) ELSE 0 END) AS nilai_masuk,
        SUM(CASE WHEN ms.jenis_mutasi = 'Keluar' THEN 1 ELSE 0 END) AS jml_transaksi_keluar,
        SUM(CASE WHEN ms.jenis_mutasi = 'Keluar' THEN ms.jumlah ELSE 0 END) AS qty_keluar,
        SUM(CASE WHEN ms.jenis_mutasi = 'Keluar' THEN ms.jumlah * COALESCE(gs.harga_satuan, 0) ELSE 0 END) AS nilai_keluar
    FROM Mutasi_Stok ms
    JOIN Gudang_Stok gs ON ms.barang_id = gs.id
    WHERE ms.jenis_mutasi IN ('Masuk','Keluar')
    GROUP BY YEAR(ms.tanggal)
    ORDER BY tahun DESC
")->fetchAll();

$rekapTahunan = [];
$grandTotalTahunan = ['transaksi_masuk' => 0, 'qty_masuk' => 0, 'nilai_masuk' => 0, 'transaksi_keluar' => 0, 'qty_keluar' => 0, 'nilai_keluar' => 0];
foreach ($rekapTahunanRows as $row) {
    $rekapTahunan[] = [
        'tahun' => (int) $row['tahun'],
        'jml_transaksi_masuk' => (int) $row['jml_transaksi_masuk'],
        'qty_masuk' => (int) $row['qty_masuk'],
        'nilai_masuk' => (float) $row['nilai_masuk'],
        'jml_transaksi_keluar' => (int) $row['jml_transaksi_keluar'],
        'qty_keluar' => (int) $row['qty_keluar'],
        'nilai_keluar' => (float) $row['nilai_keluar'],
    ];
    $grandTotalTahunan['transaksi_masuk'] += (int) $row['jml_transaksi_masuk'];
    $grandTotalTahunan['qty_masuk'] += (int) $row['qty_masuk'];
    $grandTotalTahunan['nilai_masuk'] += (float) $row['nilai_masuk'];
    $grandTotalTahunan['transaksi_keluar'] += (int) $row['jml_transaksi_keluar'];
    $grandTotalTahunan['qty_keluar'] += (int) $row['qty_keluar'];
    $grandTotalTahunan['nilai_keluar'] += (float) $row['nilai_keluar'];
}

// Tahun-tahun yang tersedia untuk dropdown filter unduh Rekap Tahunan
$tahunTersedia = array_unique(array_merge(
    array_column($rekapTahunan, 'tahun'),
    [(int) date('Y')]
));
rsort($tahunTersedia);

// Tentukan tab aktif kalau diarahkan lewat nama kategori (setelah tambah barang)
if (strpos($active_tab, 'tabKatByName:') === 0) {
    $active_tab = 'tabGudang';
}
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
    <?php if ($import_result && !empty($import_result['errors'])): ?>
        <div class="alert alert-danger-custom align-items-start" style="flex-direction:column;">
            <strong><i class="bi bi-exclamation-triangle-fill"></i> Detail baris gagal import:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach (array_slice($import_result['errors'], 0, 15) as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Toolbar Global: Import CSV/Excel (di atas semua tab) -->
    <div class="card-box mb-3">
        <div class="table-toolbar" style="border-bottom:none;">
            <div>
                <h5 class="table-toolbar-title fw-bold mb-1"><i class="bi bi-boxes me-1"></i> Gudang &amp; Stock
                    Opname</h5>
                <small class="text-muted">Kelola stok per kategori, catat pemakaian &amp; barang masuk, atau import
                    data dari file Stock Opname (CSV/Excel).</small>
            </div>
            <div class="table-toolbar-actions">
                <button class="btn-primary-custom" onclick="openModal('modalImport')">
                    <i class="bi bi-file-earmark-arrow-up"></i> Import CSV/Excel
                </button>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav" style="flex-wrap:wrap;">
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabGudang' ? ' active' : '' ?>"
                data-tab-target="tabGudang" onclick="switchTab('tabGudang', this)">
                <i class="bi bi-boxes me-1"></i> Gudang Barang
                <span class="badge-secondary ms-1"><?= count($semuaItems) ?></span>
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabBarangMasuk' ? ' active' : '' ?>"
                data-tab-target="tabBarangMasuk" onclick="switchTab('tabBarangMasuk', this)">
                <i class="bi bi-box-arrow-in-down me-1"></i> Barang Masuk
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabTransaksi' ? ' active' : '' ?>"
                data-tab-target="tabTransaksi" onclick="switchTab('tabTransaksi', this)">
                <i class="bi bi-arrow-left-right me-1"></i> Transaksi
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabKeuangan' ? ' active' : '' ?>"
                data-tab-target="tabKeuangan" onclick="switchTab('tabKeuangan', this)">
                <i class="bi bi-cash-coin me-1"></i> Keuangan
            </button>
        </div>

        <div class="row g-4">
            <!-- ===================== TAB GUDANG BARANG (gabungan ATK/AAK3/Konsumsi/Kebersihan) ===================== -->
            <div class="col-12 arp-tab-panel" id="tabGudang" <?= $active_tab === 'tabGudang' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar table-toolbar-stack" style="flex-wrap:wrap; gap:.75rem;">
                        <h5 class="table-toolbar-title fw-bold">Daftar Barang Gudang</h5>
                        <div class="table-toolbar-actions" style="flex-wrap:wrap; align-items:center;">
                            <!-- Filter Kategori: dropdown, sub-kategori (ATK/AAK3/Konsumsi/Kebersihan) hanya
                                 muncul ketika tombol dropdown ini diklik -->
                            <div class="arp-dropdown-kategori" id="dropdownKategoriWrapper">
                                <button type="button" class="arp-dropdown-kategori-btn" id="btnDropdownKategori"
                                    onclick="toggleDropdownKategori()">
                                    <i class="bi bi-grid-3x3-gap-fill"></i>
                                    <span id="labelFilterKategoriAktif">Semua Kategori</span>
                                    <span class="badge-secondary"
                                        id="badgeFilterKategoriAktif"><?= count($semuaItems) ?></span>
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                                <div class="arp-dropdown-kategori-menu" id="dropdownKategoriMenu">
                                    <a href="javascript:void(0)"
                                        class="arp-dropdown-kategori-item filter-kategori-btn active"
                                        data-kategori-filter="" data-kategori-label="Semua Kategori"
                                        data-kategori-icon="bi-grid-3x3-gap-fill" onclick="setFilterKategori('', this)">
                                        <i class="bi bi-grid-3x3-gap-fill me-1"></i> Semua Kategori
                                        <span class="badge-secondary"><?= count($semuaItems) ?></span>
                                    </a>
                                    <?php foreach ($kategoris as $kat): ?>
                                        <a href="javascript:void(0)" class="arp-dropdown-kategori-item filter-kategori-btn"
                                            data-kategori-filter="<?= $kat['id_kategori'] ?>"
                                            data-kategori-label="<?= htmlspecialchars($kat['nama_kategori']) ?>"
                                            data-kategori-icon="<?= stockKategoriIcon($kat['nama_kategori']) ?>"
                                            onclick="setFilterKategori('<?= $kat['id_kategori'] ?>', this)">
                                            <i class="bi <?= stockKategoriIcon($kat['nama_kategori']) ?> me-1"></i>
                                            <?= htmlspecialchars($kat['nama_kategori']) ?>
                                            <span
                                                class="badge-secondary"><?= count($itemsByKategori[$kat['id_kategori']]) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari barang..." id="searchGudang"
                                    onkeyup="filterTabelGudang()">
                            </div>
                            <select class="select-custom" id="filterJenisPakai" style="max-width:180px;"
                                onchange="filterTabelGudang()">
                                <option value="">Semua Jenis Pakai</option>
                                <option value="Habis Pakai">Habis Pakai</option>
                                <option value="Tidak Habis Pakai">Tidak Habis Pakai</option>
                            </select>
                            <button class="btn-secondary-custom" onclick="openModal('modalTambahBarang')">
                                <i class="bi bi-plus-lg"></i> Tambah Barang
                            </button>
                        </div>
                    </div>


                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelGudang">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Nama Barang</th>
                                    <th>Kategori</th>
                                    <th>Jenis Pakai</th>
                                    <th>Stok Awal</th>
                                    <th>Pemakaian</th>
                                    <th>Sisa Stok</th>
                                    <th>Satuan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($semuaItems) === 0): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">Belum ada barang di
                                            gudang. Tambah manual atau import dari file Excel/CSV.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($semuaItems as $it): ?>
                                        <tr data-kategori-id="<?= $it['id_kategori'] ?>"
                                            data-jenis-pakai="<?= htmlspecialchars($it['jenis_pakai'] ?? 'Habis Pakai') ?>">
                                            <td><strong><?= htmlspecialchars($it['kode_barang']) ?></strong></td>
                                            <td><?= htmlspecialchars($it['nama_barang']) ?></td>
                                            <td><span
                                                    class="badge-secondary"><?= htmlspecialchars($it['nama_kategori']) ?></span>
                                            </td>
                                            <td>
                                                <?php if (($it['jenis_pakai'] ?? 'Habis Pakai') === 'Tidak Habis Pakai'): ?>
                                                    <span class="badge-secondary">Tidak Habis Pakai</span>
                                                <?php else: ?>
                                                    <span class="badge-success">Habis Pakai</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= (int) $it['stok_awal'] ?></td>
                                            <td><span class="badge-warning"><?= (int) $it['pemakaian'] ?></span></td>
                                            <td><strong><?= (int) $it['stok_sistem'] ?></strong></td>
                                            <td><?= htmlspecialchars($it['satuan']) ?></td>
                                            <td>
                                                <button type="button" class="btn-secondary-custom py-1 px-2" onclick='openModalEdit(<?= json_encode([
                                                    "id" => $it["id"],
                                                    "nama" => $it["nama_barang"],
                                                    "satuan" => $it["satuan"],
                                                    "rak" => $it["lokasi_rak"],
                                                    "jenis_pakai" => $it["jenis_pakai"] ?? "Habis Pakai",
                                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelGudang"></div>
                </div>
            </div>

            <!-- ===================== TAB BARANG MASUK ===================== -->
            <div class="col-12 arp-tab-panel" id="tabBarangMasuk" <?= $active_tab === 'tabBarangMasuk' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Riwayat Barang Masuk (Pembelian)</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari barang masuk..."
                                    data-table-search="tabelBarangMasuk"
                                    onkeyup="handleTableSearch('tabelBarangMasuk')">
                            </div>
                            <button class="btn-primary-custom" onclick="openModal('modalBarangMasuk')">
                                <i class="bi bi-plus-lg"></i> Catat Barang Masuk
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelBarangMasuk">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Kode</th>
                                    <th>Nama Barang</th>
                                    <th>Kategori</th>
                                    <th>Volume</th>
                                    <th>Keterangan</th>
                                    <th>Dicatat Oleh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($barangMasukList) === 0): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">Belum ada riwayat barang
                                            masuk.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php $noMasuk = 1;
                                    foreach ($barangMasukList as $bm): ?>
                                        <tr>
                                            <td><?= $noMasuk++ ?></td>
                                            <td><?= date('d-m-Y', strtotime($bm['tanggal'])) ?></td>
                                            <td><?= htmlspecialchars($bm['kode_barang']) ?></td>
                                            <td><strong><?= htmlspecialchars($bm['nama_barang']) ?></strong></td>
                                            <td><span
                                                    class="badge-secondary"><?= htmlspecialchars($bm['nama_kategori']) ?></span>
                                            </td>
                                            <td><?= (int) $bm['jumlah'] ?>         <?= htmlspecialchars($bm['satuan']) ?></td>
                                            <td><?= htmlspecialchars($bm['keterangan'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($bm['operator']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelBarangMasuk"></div>
                </div>
            </div>

            <!-- ===================== TAB TRANSAKSI (Pemakaian) ===================== -->
            <div class="col-12 arp-tab-panel" id="tabTransaksi" <?= $active_tab === 'tabTransaksi' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Riwayat Transaksi Pemakaian Barang</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari transaksi..."
                                    data-table-search="tabelTransaksi" onkeyup="handleTableSearch('tabelTransaksi')">
                            </div>
                            <button class="btn-primary-custom" onclick="openModal('modalPemakaianGlobal')">
                                <i class="bi bi-box-arrow-up"></i> Form Penggunaan Barang
                            </button>
                            <button class="btn-primary-custom" onclick="openModal('modalRekapTransaksi')">
                                <i class="bi bi-file-earmark-pdf"></i> Rekap Bulanan (PDF)
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelTransaksi">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Kode Barang</th>
                                    <th>Nama Barang</th>
                                    <th>Kategori</th>
                                    <th>Tanggal</th>
                                    <th>Volume</th>
                                    <th>Pemakai</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($transaksiList) === 0): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">Belum ada transaksi
                                            pemakaian.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php $no = 1;
                                    foreach ($transaksiList as $tr): ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td><strong><?= htmlspecialchars($tr['kode_barang']) ?></strong></td>
                                            <td><?= htmlspecialchars($tr['nama_barang']) ?></td>
                                            <td><span
                                                    class="badge-secondary"><?= htmlspecialchars($tr['nama_kategori']) ?></span>
                                            </td>
                                            <td><?= date('d-m-Y', strtotime($tr['tanggal'])) ?></td>
                                            <td><?= (int) $tr['jumlah'] ?>         <?= htmlspecialchars($tr['satuan']) ?></td>
                                            <td><?= htmlspecialchars($tr['pemakai'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($tr['keterangan'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelTransaksi"></div>
                </div>
            </div>

            <!-- ===================== TAB KEUANGAN ===================== -->
            <div class="col-12 arp-tab-panel" id="tabKeuangan" <?= $active_tab === 'tabKeuangan' ? '' : 'style="display:none;"' ?>>
                <div class="card-box mb-3">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Rekap Anggaran Gudang per Kategori
                            (<?= $tahunAnggaran ?>)</h5>
                        <div class="table-toolbar-actions">
                            <a class="btn-primary-custom"
                                href="stock.php?export=rekap_anggaran_kategori_pdf&tahun_anggaran=<?= $tahunAnggaran ?>"
                                target="_blank">
                                <i class="bi bi-file-earmark-pdf"></i> Unduh PDF
                            </a>
                            <form method="GET" class="d-inline-flex align-items-center gap-2" style="margin:0;">
                                <input type="hidden" name="tab" value="tabKeuangan">
                                <select class="select-custom" name="tahun_anggaran" style="max-width:130px;"
                                    onchange="this.form.submit()">
                                    <?php foreach ($tahunAnggaranTersedia as $thnOpt): ?>
                                        <option value="<?= $thnOpt ?>" <?= $thnOpt === $tahunAnggaran ? 'selected' : '' ?>>
                                            <?= $thnOpt ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>No</th>
                                    <th>Uraian</th>
                                    <th>Vol</th>
                                    <th>Sat</th>
                                    <th>Harga Satuan</th>
                                    <th>Jumlah</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($grandTotalAnggaran == 0 && array_sum(array_map(fn($r) => count($r['items']), $rekapAnggaran)) === 0): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">Belum ada kategori/barang
                                            gudang untuk direkap.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rekapAnggaran as $catRekap): ?>
                                        <tr style="background:rgba(0,0,0,.02);font-weight:700;">
                                            <td>0.<?= $catRekap['no'] ?></td>
                                            <td>1.<?= $catRekap['no'] ?></td>
                                            <td colspan="6"><?= htmlspecialchars($catRekap['nama']) ?></td>
                                        </tr>
                                        <?php if (count($catRekap['items']) === 0): ?>
                                            <tr>
                                                <td></td>
                                                <td colspan="7" class="text-muted">Belum ada barang di kategori ini.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($catRekap['items'] as $itRekap): ?>
                                            <tr>
                                                <td></td>
                                                <td><?= htmlspecialchars($itRekap['kode']) ?></td>
                                                <td><?= htmlspecialchars($itRekap['nama']) ?></td>
                                                <td><?= $itRekap['vol'] ?></td>
                                                <td><?= htmlspecialchars($itRekap['satuan']) ?></td>
                                                <td>Rp <?= number_format($itRekap['harga'], 0, ',', '.') ?></td>
                                                <td>Rp <?= number_format($itRekap['nilai'], 0, ',', '.') ?></td>
                                                <td>
                                                    <button type="button" class="btn-secondary-custom py-1 px-2"
                                                        onclick='openModalEditHarga(<?= json_encode([
                                                            "id" => $itRekap["id"],
                                                            "nama" => $itRekap["nama"],
                                                            "harga" => $itRekap["harga"] ?: null,
                                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                        <i class="bi bi-cash"></i> Atur Harga
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr style="font-weight:700;">
                                            <td colspan="6" class="text-end">Subtotal <?= htmlspecialchars($catRekap['nama']) ?>
                                            </td>
                                            <td>Rp <?= number_format($catRekap['subtotal'], 0, ',', '.') ?></td>
                                            <td></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-box mb-3">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Ringkasan per Kategori &amp; TOTAL
                            (<?= $tahunAnggaran ?>)</h5>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Kategori</th>
                                    <th>Nilai (Rp)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rekapAnggaran as $catRekap): ?>
                                    <tr>
                                        <td>0.<?= $catRekap['no'] ?></td>
                                        <td><?= htmlspecialchars($catRekap['nama']) ?></td>
                                        <td>Rp <?= number_format($catRekap['subtotal'], 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight:700;background:rgba(0,0,0,.02);">
                                    <td colspan="2">TOTAL</td>
                                    <td>Rp <?= number_format($grandTotalAnggaran, 0, ',', '.') ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Import CSV/Excel -->
    <div class="arp-modal-overlay" id="modalImport" onclick="closeModalOutside(event, 'modalImport')">
        <div class="arp-modal-box" style="max-width:520px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0">Import Stock Opname (CSV/Excel)</h5>
                    <small class="text-muted">Pilih kategori (tab) tujuan, lalu unggah file</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalImport')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="stock.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_stok">
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Kategori Tujuan (Tab) *</label>
                        <select name="id_kategori_import" class="select-custom" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategoris as $kat): ?>
                                <option value="<?= $kat['id_kategori'] ?>"><?= htmlspecialchars($kat['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Data akan otomatis masuk ke tab sesuai kategori yang dipilih di
                            sini.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">File (.csv atau .xlsx) *</label>
                        <div class="upload-dropzone" id="dropzoneImportStok">
                            <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                            <div>
                                <span class="fw-semibold" style="color: var(--primary);">Tarik &amp; lepas file di
                                    sini</span>
                                atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                            </div>
                            <span class="fs-7 text-muted">Format: CSV, XLSX</span>
                            <input type="file" name="file_import" id="inputImportStok" class="d-none"
                                accept=".csv,.xlsx" required>
                            <div class="upload-dropzone-filelist" id="fileListImportStok"></div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <small class="text-muted">
                            Kolom yang dikenali: <strong>Kode Barang, Nama Barang, Unit/Satuan, Volume (Stok Awal),
                                Volume2 (Pemakaian), Harga Satuan</strong>. Kolom Sisa Stok dihitung otomatis oleh
                            sistem — tidak perlu diisi.
                        </small>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalImport')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Import Sekarang</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Form Penggunaan Barang (alur: pilih Kategori -> Nama Barang & Kode) -->
    <div class="arp-modal-overlay" id="modalPemakaianGlobal" onclick="closeModalOutside(event, 'modalPemakaianGlobal')">
        <div class="arp-modal-box" style="max-width:520px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0">Form Penggunaan Barang</h5>
                    <small class="text-muted">Catat pemakaian, sisa stok terupdate otomatis</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalPemakaianGlobal')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="stock.php">
                    <input type="hidden" name="action" value="pemakaian">
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">1. Pilih Kategori *</label>
                        <select class="select-custom" id="pemakaianKategori" required
                            onchange="renderPemakaianBarangOptions()">
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategoris as $kat): ?>
                                <option value="<?= $kat['id_kategori'] ?>"><?= htmlspecialchars($kat['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">2. Pilih Barang (Kode &amp; Nama) *</label>
                        <select name="barang_id" class="select-custom" id="pemakaianBarang" required disabled>
                            <option value="">-- Pilih kategori dahulu --</option>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Jumlah Dipakai *</label>
                            <input type="number" name="jumlah" class="form-control-custom" min="1"
                                placeholder="Contoh: 5" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Tanggal</label>
                            <input type="date" name="tanggal" class="form-control-custom" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Nama Pemakai *</label>
                        <input type="text" name="pemakai" class="form-control-custom" placeholder="Contoh: Arya"
                            required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Keterangan</label>
                        <textarea name="keterangan" class="textarea-custom"
                            placeholder="Contoh: kebutuhan kantor / nama klien"></textarea>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalPemakaianGlobal')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Catat Pemakaian</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Catat Barang Masuk -->
    <div class="arp-modal-overlay" id="modalBarangMasuk" onclick="closeModalOutside(event, 'modalBarangMasuk')">
        <div class="arp-modal-box" style="max-width:520px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0">Catat Barang Masuk</h5>
                    <small class="text-muted">Pembelian / restock, stok akan otomatis bertambah</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalBarangMasuk')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="stock.php">
                    <input type="hidden" name="action" value="barang_masuk">
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">1. Pilih Kategori *</label>
                        <select class="select-custom" id="masukKategori" required onchange="renderBarangMasukOptions()">
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategoris as $kat): ?>
                                <option value="<?= $kat['id_kategori'] ?>"><?= htmlspecialchars($kat['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">2. Pilih Barang (Kode &amp; Nama) *</label>
                        <select name="barang_id" class="select-custom" id="masukBarang" required disabled>
                            <option value="">-- Pilih kategori dahulu --</option>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Jumlah Masuk *</label>
                            <input type="number" name="jumlah" class="form-control-custom" min="1"
                                placeholder="Contoh: 10" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Tanggal</label>
                            <input type="date" name="tanggal" class="form-control-custom" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Keterangan</label>
                        <textarea name="keterangan" class="textarea-custom"
                            placeholder="Contoh: Pembelian dari toko ABC"></textarea>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalBarangMasuk')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Catat Barang Masuk</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Rekap Bulanan Transaksi Pemakaian (Surat PDF) -->
    <div class="arp-modal-overlay" id="modalRekapTransaksi" onclick="closeModalOutside(event, 'modalRekapTransaksi')">
        <div class="arp-modal-box" style="max-width:480px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0">Rekap Bulanan Transaksi Pemakaian</h5>
                    <small class="text-muted">Diunduh dalam bentuk surat PDF</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalRekapTransaksi')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="GET" action="stock.php" target="_blank">
                    <input type="hidden" name="export" value="rekap_transaksi_pdf">
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Pilih Bulan *</label>
                        <input type="month" name="bulan" class="form-control-custom" value="<?= date('Y-m') ?>"
                            required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Kategori</label>
                        <select name="id_kategori" class="select-custom">
                            <option value="0">Semua Kategori</option>
                            <?php foreach ($kategoris as $kat): ?>
                                <option value="<?= $kat['id_kategori'] ?>"><?= htmlspecialchars($kat['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalRekapTransaksi')">Batal</button>
                        <button type="submit" class="btn-primary-custom"><i class="bi bi-download"></i> Unduh
                            PDF</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Tambah Barang Baru -->
    <div class="arp-modal-overlay" id="modalTambahBarang" onclick="closeModalOutside(event, 'modalTambahBarang')">
        <div class="arp-modal-box" style="max-width:560px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0">Tambah Barang Baru</h5>
                    <small class="text-muted">Daftarkan item baru ke dalam gudang inventaris</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalTambahBarang')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="stock.php" id="formTambahBarang">
                    <input type="hidden" name="action" value="tambah_barang">

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Kategori Barang *</label>
                        <select name="kategori_pilih" class="select-custom" id="tambahKategoriSelect" required
                            onchange="updatePreviewKodeBarang()">
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategoris as $kat): ?>
                                <option value="<?= htmlspecialchars($kat['nama_kategori']) ?>">
                                    <?= htmlspecialchars($kat['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Pilih kategori terlebih dahulu, kode barang akan dibuat otomatis
                            sesuai aturan penomoran kategori ini.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Nama Barang Lengkap *</label>
                        <input type="text" name="nama_barang" class="form-control-custom"
                            placeholder="Contoh: Meteran Laser 50m" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Kode Barang</label>
                        <div class="form-control-custom"
                            style="background:rgba(0,0,0,.03); font-weight:600; display:flex; align-items:center;"
                            id="tambahKodePreview">Pilih kategori dahulu</div>
                        <small class="text-muted">Kode dibuat otomatis mengikuti kategori (format 1.x = ATK, 2.x =
                            AAK3, 3.x = Konsumsi, 4.x = Kebersihan).</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Jenis Pakai *</label>
                        <select name="jenis_pakai" class="select-custom" required>
                            <option value="Habis Pakai">Habis Pakai (mis. kertas, tinta, galon)</option>
                            <option value="Tidak Habis Pakai">Tidak Habis Pakai (mis. alat/perkakas, aset tahan
                                lama)</option>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Satuan Ukur *</label>
                            <input type="text" name="satuan" class="form-control-custom"
                                placeholder="Contoh: Pcs, Unit, Box" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Stok Awal *</label>
                            <input type="number" name="stok_awal" class="form-control-custom" min="0" value="0"
                                required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Lokasi Penyimpanan / Rak</label>
                        <input type="text" name="lokasi_rak" class="form-control-custom"
                            placeholder="Contoh: Rak A-3, Gudang Lantai 2">
                        <small class="text-muted">Harga satuan diatur belakangan lewat tab Keuangan → Daftar Harga
                            Satuan Barang.</small>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalTambahBarang')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Simpan Barang</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Edit Barang -->
    <div class="arp-modal-overlay" id="modalEditBarang" onclick="closeModalOutside(event, 'modalEditBarang')">
        <div class="arp-modal-box" style="max-width:480px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0" id="editBarangNama">Edit Barang</h5>
                    <small class="text-muted">Ubah satuan, rak &amp; jenis pakai</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalEditBarang')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="stock.php">
                    <input type="hidden" name="action" value="edit_barang">
                    <input type="hidden" name="barang_id" id="editBarangId">
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Satuan</label>
                        <input type="text" name="satuan" id="editSatuan" class="form-control-custom" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Jenis Pakai</label>
                        <select name="jenis_pakai" id="editJenisPakai" class="select-custom">
                            <option value="Habis Pakai">Habis Pakai</option>
                            <option value="Tidak Habis Pakai">Tidak Habis Pakai</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Lokasi Rak</label>
                        <input type="text" name="lokasi_rak" id="editRak" class="form-control-custom">
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalEditBarang')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Atur Harga Satuan (dari tab Keuangan) -->
    <div class="arp-modal-overlay" id="modalEditHarga" onclick="closeModalOutside(event, 'modalEditHarga')">
        <div class="arp-modal-box" style="max-width:420px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0" id="editHargaNama">Atur Harga Satuan</h5>
                    <small class="text-muted">Dipakai untuk perhitungan Rekap Keuangan</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalEditHarga')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="stock.php">
                    <input type="hidden" name="action" value="edit_harga">
                    <input type="hidden" name="barang_id" id="editHargaBarangId">
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Harga Satuan (Rp) *</label>
                        <input type="number" name="harga_satuan" id="editHargaSatuan" class="form-control-custom"
                            min="0" placeholder="Contoh: 15000" required>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalEditHarga')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Simpan Harga</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
    // Data semua barang (untuk cascading select Kategori -> Barang di Form Penggunaan Barang)
    const semuaBarangData = <?= json_encode(array_map(function ($b) {
        return [
            'id' => $b['id'],
            'kode' => $b['kode_barang'],
            'nama' => $b['nama_barang'],
            'satuan' => $b['satuan'],
            'stok' => (int) $b['stok_sistem'],
            'id_kategori' => null, // diisi ulang di bawah dari $semuaItems supaya id_kategori akurat
        ];
    }, $semuaBarang), JSON_UNESCAPED_UNICODE) ?>;

    // Sumber id_kategori yang akurat per barang (dari data tab Gudang Barang)
    const petaKategoriBarang = <?= json_encode(array_combine(
        array_map(fn($it) => $it['id'], $semuaItems),
        array_map(fn($it) => $it['id_kategori'], $semuaItems)
    ), JSON_UNESCAPED_UNICODE) ?>;
    semuaBarangData.forEach(function (b) {
        b.id_kategori = petaKategoriBarang[b.id] ?? null;
    });

    // Daftar kategori (untuk preview Kode Barang otomatis di form Tambah Barang)
    const semuaKategoriData = <?= json_encode(array_map(function ($kat) {
        return ['id_kategori' => $kat['id_kategori'], 'nama_kategori' => $kat['nama_kategori']];
    }, $kategoris), JSON_UNESCAPED_UNICODE) ?>;

    // Prefix kode barang sesuai kategori (meniru stockKodePrefix() di
    // includes/stock_import_helper.php: 1=ATK, 2=AAK3, 3=Konsumsi, 4=Kebersihan, 9=lainnya)
    function stockKodePrefixJs(namaKategori) {
        const nama = (namaKategori || '').toLowerCase().trim();
        if (nama.indexOf('atk') !== -1) return '1';
        if (nama.indexOf('aak3') !== -1 || nama.indexOf('ahli') !== -1) return '2';
        if (nama.indexOf('konsumsi') !== -1) return '3';
        if (nama.indexOf('bersih') !== -1) return '4';
        return '9';
    }

    // Preview kode barang otomatis di form Tambah Barang, mengikuti kategori yang dipilih
    function updatePreviewKodeBarang() {
        const selectEl = document.getElementById('tambahKategoriSelect');
        const previewEl = document.getElementById('tambahKodePreview');
        if (!selectEl || !previewEl) return;

        const namaKategori = selectEl.value;
        if (!namaKategori) {
            previewEl.textContent = 'Pilih kategori dahulu';
            return;
        }

        const kat = semuaKategoriData.find(function (k) { return k.nama_kategori === namaKategori; });
        const prefix = stockKodePrefixJs(namaKategori);
        let maxUrut = 0;
        if (kat) {
            semuaBarangData.forEach(function (b) {
                if (String(b.id_kategori) !== String(kat.id_kategori)) return;
                const m = String(b.kode || '').trim().match(new RegExp('^' + prefix + '\\.(\\d+)$'));
                if (m) maxUrut = Math.max(maxUrut, parseInt(m[1], 10));
            });
        }
        previewEl.textContent = prefix + '.' + (maxUrut + 1) + ' (otomatis)';
    }

    // Reset preview kode & form setiap kali modal Tambah Barang dibuka
    document.querySelector('button[onclick="openModal(\'modalTambahBarang\')"]')?.addEventListener('click', function () {
        const form = document.getElementById('formTambahBarang');
        if (form) form.reset();
        updatePreviewKodeBarang();
    });

    document.addEventListener('DOMContentLoaded', function () {
        initGudangTable();
        initTablePagination('tabelBarangMasuk', 10);
        initTablePagination('tabelTransaksi', 10);
    });

    /* =========================================================
       TAB GUDANG BARANG — filter Kategori (klik) + Jenis Pakai
       (select) + pencarian teks, dipadukan dengan pagination
       sendiri (tidak numpang ke initTablePagination bawaan,
       supaya filter kategori/jenis-pakai bisa jalan bareng).
       ========================================================= */
    const gudangTableState = { rowsPerPage: 10, currentPage: 1, kategoriFilter: '' };

    function initGudangTable() {
        renderGudangTable();
    }

    function setFilterKategori(idKategori, btnEl) {
        gudangTableState.kategoriFilter = idKategori;
        gudangTableState.currentPage = 1;
        document.querySelectorAll('.filter-kategori-btn').forEach(function (b) {
            b.classList.remove('active');
        });
        if (btnEl) {
            btnEl.classList.add('active');
            const label = btnEl.getAttribute('data-kategori-label') || 'Semua Kategori';
            const icon = btnEl.getAttribute('data-kategori-icon') || 'bi-grid-3x3-gap-fill';
            const badge = btnEl.querySelector('.badge-secondary');
            const labelEl = document.getElementById('labelFilterKategoriAktif');
            const badgeEl = document.getElementById('badgeFilterKategoriAktif');
            const iconEl = document.querySelector('#btnDropdownKategori i.bi:first-child');
            if (labelEl) labelEl.textContent = label;
            if (badgeEl && badge) badgeEl.textContent = badge.textContent;
            if (iconEl) iconEl.className = 'bi ' + icon;
        }
        renderGudangTable();
        closeDropdownKategori();
    }

    function toggleDropdownKategori() {
        const menu = document.getElementById('dropdownKategoriMenu');
        const btn = document.getElementById('btnDropdownKategori');
        if (!menu || !btn) return;
        const isOpen = menu.classList.contains('show');
        if (isOpen) {
            closeDropdownKategori();
        } else {
            menu.classList.add('show');
            btn.classList.add('open');
        }
    }

    function closeDropdownKategori() {
        const menu = document.getElementById('dropdownKategoriMenu');
        const btn = document.getElementById('btnDropdownKategori');
        if (menu) menu.classList.remove('show');
        if (btn) btn.classList.remove('open');
    }

    document.addEventListener('click', function (e) {
        const wrapper = document.getElementById('dropdownKategoriWrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            closeDropdownKategori();
        }
    });

    function filterTabelGudang() {
        gudangTableState.currentPage = 1;
        renderGudangTable();
    }

    function getGudangFilteredRows() {
        const table = document.getElementById('tabelGudang');
        if (!table) return [];
        const allRows = Array.from(table.querySelectorAll('tbody tr')).filter(function (row) {
            return row.hasAttribute('data-kategori-id');
        });

        const query = (document.getElementById('searchGudang')?.value || '').trim().toLowerCase();
        const jenisPakai = document.getElementById('filterJenisPakai')?.value || '';
        const kategoriFilter = gudangTableState.kategoriFilter;

        return allRows.filter(function (row) {
            if (kategoriFilter && row.getAttribute('data-kategori-id') !== String(kategoriFilter)) return false;
            if (jenisPakai && row.getAttribute('data-jenis-pakai') !== jenisPakai) return false;
            if (query && row.textContent.toLowerCase().indexOf(query) === -1) return false;
            return true;
        });
    }

    function renderGudangTable() {
        const table = document.getElementById('tabelGudang');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        const allRows = Array.from(tbody.querySelectorAll('tr[data-kategori-id]'));
        const filteredRows = getGudangFilteredRows();
        const totalRows = filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(totalRows / gudangTableState.rowsPerPage));
        if (gudangTableState.currentPage > totalPages) gudangTableState.currentPage = totalPages;

        allRows.forEach(function (row) { row.style.display = 'none'; });

        const start = (gudangTableState.currentPage - 1) * gudangTableState.rowsPerPage;
        filteredRows.slice(start, start + gudangTableState.rowsPerPage).forEach(function (row) {
            row.style.display = '';
        });

        let emptyRow = tbody.querySelector('tr[data-search-empty="true"]');
        if (totalRows === 0 && allRows.length > 0) {
            if (!emptyRow) {
                emptyRow = document.createElement('tr');
                emptyRow.setAttribute('data-search-empty', 'true');
                emptyRow.innerHTML = '<td colspan="9" class="text-center py-4 text-muted">Data tidak ditemukan.</td>';
                tbody.appendChild(emptyRow);
            }
            emptyRow.style.display = '';
        } else if (emptyRow) {
            emptyRow.style.display = 'none';
        }

        renderGudangPaginationControls(totalRows, totalPages);
    }

    function goToGudangPage(page) {
        const totalPages = Math.max(1, Math.ceil(getGudangFilteredRows().length / gudangTableState.rowsPerPage));
        if (page < 1 || page > totalPages) return;
        gudangTableState.currentPage = page;
        renderGudangTable();
    }

    function renderGudangPaginationControls(totalRows, totalPages) {
        const container = document.getElementById('pagination-tabelGudang');
        if (!container) return;
        const start = totalRows === 0 ? 0 : (gudangTableState.currentPage - 1) * gudangTableState.rowsPerPage + 1;
        const end = Math.min(gudangTableState.currentPage * gudangTableState.rowsPerPage, totalRows);

        let html = '<div class="pagination-info text-muted" style="font-size:0.875rem;">Menampilkan ' + start + '-' + end + ' dari ' + totalRows + ' data</div>';
        html += '<ul class="pagination-pages">';
        const prevDisabled = gudangTableState.currentPage === 1;
        html += '<li class="pagination-item' + (prevDisabled ? ' disabled' : '') + '"><a href="javascript:void(0)"' + (prevDisabled ? '' : ' onclick="goToGudangPage(' + (gudangTableState.currentPage - 1) + ')"') + '><i class="bi bi-chevron-left"></i></a></li>';
        for (let p = 1; p <= totalPages; p++) {
            const isActive = p === gudangTableState.currentPage;
            html += '<li class="pagination-item' + (isActive ? ' active' : '') + '"><span style="cursor:pointer;" onclick="goToGudangPage(' + p + ')">' + p + '</span></li>';
        }
        const nextDisabled = gudangTableState.currentPage === totalPages;
        html += '<li class="pagination-item' + (nextDisabled ? ' disabled' : '') + '"><a href="javascript:void(0)"' + (nextDisabled ? '' : ' onclick="goToGudangPage(' + (gudangTableState.currentPage + 1) + ')"') + '><i class="bi bi-chevron-right"></i></a></li>';
        html += '</ul>';
        container.innerHTML = html;
    }

    // Buka modal Edit Barang dan isi field dari data barang yang diklik
    function openModalEdit(data) {
        document.getElementById('editBarangNama').textContent = 'Edit — ' + data.nama;
        document.getElementById('editBarangId').value = data.id;
        document.getElementById('editSatuan').value = data.satuan || '';
        document.getElementById('editJenisPakai').value = data.jenis_pakai || 'Habis Pakai';
        document.getElementById('editRak').value = data.rak || '';
        openModal('modalEditBarang');
    }

    // Buka modal Atur Harga Satuan dari tab Keuangan
    function openModalEditHarga(data) {
        document.getElementById('editHargaNama').textContent = 'Atur Harga — ' + data.nama;
        document.getElementById('editHargaBarangId').value = data.id;
        document.getElementById('editHargaSatuan').value = data.harga ?? '';
        openModal('modalEditHarga');
    }

    // Cascading select: Kategori -> Barang (Kode & Nama) untuk Catat Barang Masuk
    function renderBarangMasukOptions() {
        const idKategori = document.getElementById('masukKategori').value;
        const selectBarang = document.getElementById('masukBarang');
        selectBarang.innerHTML = '';

        if (!idKategori) {
            selectBarang.disabled = true;
            selectBarang.innerHTML = '<option value="">-- Pilih kategori dahulu --</option>';
            return;
        }

        const barangKategori = semuaBarangData.filter(function (b) {
            return String(b.id_kategori) === String(idKategori);
        });

        if (barangKategori.length === 0) {
            selectBarang.disabled = true;
            selectBarang.innerHTML = '<option value="">-- Belum ada barang di kategori ini --</option>';
            return;
        }

        selectBarang.disabled = false;
        let html = '<option value="">-- Pilih Barang --</option>';
        barangKategori.forEach(function (b) {
            html += '<option value="' + b.id + '">[' + b.kode + '] ' + b.nama + ' (Stok: ' + b.stok + ' ' + b.satuan + ')</option>';
        });
        selectBarang.innerHTML = html;
    }

    // Cascading select: Kategori -> Barang (Kode & Nama) untuk Form Penggunaan Barang
    function renderPemakaianBarangOptions() {
        const idKategori = document.getElementById('pemakaianKategori').value;
        const selectBarang = document.getElementById('pemakaianBarang');
        selectBarang.innerHTML = '';

        if (!idKategori) {
            selectBarang.disabled = true;
            selectBarang.innerHTML = '<option value="">-- Pilih kategori dahulu --</option>';
            return;
        }

        const barangKategori = semuaBarangData.filter(function (b) {
            return String(b.id_kategori) === String(idKategori);
        });

        if (barangKategori.length === 0) {
            selectBarang.disabled = true;
            selectBarang.innerHTML = '<option value="">-- Belum ada barang di kategori ini --</option>';
            return;
        }

        selectBarang.disabled = false;
        let html = '<option value="">-- Pilih Barang --</option>';
        barangKategori.forEach(function (b) {
            html += '<option value="' + b.id + '">[' + b.kode + '] ' + b.nama + ' (Sisa: ' + b.stok + ' ' + b.satuan + ')</option>';
        });
        selectBarang.innerHTML = html;
    }
</script>

<?php
include "../includes/footer.php";
?>