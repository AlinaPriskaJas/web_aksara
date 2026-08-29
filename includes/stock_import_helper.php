<?php
/**
 * includes/stock_import_helper.php
 *
 * Logika import CSV/Excel (Stock Opname) ke tabel Gudang_Stok, dikelompokkan
 * per kategori (tab) sesuai kategori yang dipilih user di modal import.
 *
 * Format kolom yang dikenali (header baris pertama, bebas urutan, tidak
 * case-sensitive), meniru header di file Excel Stock Opname perusahaan:
 *   - Kode Barang        -> kode_barang   (opsional, auto-generate jika kosong)
 *   - Nama Barang         -> nama_barang   (wajib)
 *   - Unit / Satuan       -> satuan        (wajib)
 *   - Volume / Sisa Barang / Stok Awal   -> stok_awal   (wajib, angka)
 *   - Volume2 / Pemakaian                -> pemakaian   (opsional, angka, default 0)
 *   - Harga Satuan / Harga                -> harga_satuan (opsional, angka)
 *
 * Kolom "Sisa Stock Opname / Volume3" TIDAK perlu diimport karena selalu
 * dihitung otomatis oleh sistem (stok_awal - pemakaian).
 */

require_once __DIR__ . '/simple_xlsx_reader.php';

/**
 * Prefix kode barang standar sesuai kategori (meniru penomoran di file Excel
 * Stock Opname: 1.x = ATK, 2.x = AAK3, 3.x = Konsumsi, 4.x = Kebersihan).
 * Kategori baru di luar 4 standar ini akan dapat prefix "9".
 */
if (!function_exists('stockKodePrefix')) {
    function stockKodePrefix(string $namaKategori): string
    {
        $nama = strtolower(trim($namaKategori));
        if (strpos($nama, 'atk') !== false) {
            return '1';
        }
        if (strpos($nama, 'aak3') !== false || strpos($nama, 'ahli') !== false) {
            return '2';
        }
        if (strpos($nama, 'konsumsi') !== false) {
            return '3';
        }
        if (strpos($nama, 'bersih') !== false) {
            return '4';
        }
        return '9';
    }
}

/**
 * Buat kode barang baru berformat "{prefix}.{urutan}" (mis. "1.16") berdasarkan
 * kategori tujuan. Urutan diambil dari nomor terbesar yang sudah dipakai di
 * kategori tersebut + 1, supaya konsisten dengan penomoran manual di Excel.
 */
if (!function_exists('stockGenerateKodeBarang')) {
    function stockGenerateKodeBarang(PDO $conn, int $id_kategori, string $namaKategori): string
    {
        $prefix = stockKodePrefix($namaKategori);
        $stmt = $conn->prepare("SELECT gs.kode_barang FROM Gudang_Stok gs
            JOIN jenis_barang_gudang jbg ON gs.id_jenis = jbg.id_jenis
            WHERE jbg.id_kategori = :id_kategori");
        $stmt->execute(['id_kategori' => $id_kategori]);

        $maxUrut = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $kode) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '\.(\d+)$/', trim((string) $kode), $m)) {
                $maxUrut = max($maxUrut, (int) $m[1]);
            }
        }

        return $prefix . '.' . ($maxUrut + 1);
    }
}

/**
 * Normalisasi 1 baris header mentah menjadi peta: nama_kolom_standar => index kolom
 */
function stockImportMapHeader(array $headerRow): array
{
    $map = [];
    $aliases = [
        'kode_barang' => ['kode barang', 'kode', 'no', 'no barang', 'code', 'sku'],
        'nama_barang' => ['nama barang', 'nama', 'uraian', 'nama item'],
        'satuan' => ['unit', 'satuan', 'sat', 'unit2', 'satuan1'],
        'stok_awal' => ['volume', 'sisa barang', 'stok awal', 'vol', 'stok sistem', 'qty'],
        'pemakaian' => ['volume2', 'pemakaian', 'vol2', 'pemakaian bulan ini'],
        'harga_satuan' => ['harga satuan', 'harga', 'unit price'],
        'keterangan' => ['keterangan', 'catatan', 'note'],
    ];

    foreach ($headerRow as $idx => $rawLabel) {
        $label = strtolower(trim((string) $rawLabel));
        $label = preg_replace('/\s+/', ' ', $label);
        if ($label === '') {
            continue;
        }
        foreach ($aliases as $std => $variants) {
            if (isset($map[$std])) {
                continue;
            }
            if (in_array($label, $variants, true)) {
                $map[$std] = $idx;
            }
        }
    }
    return $map;
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

/**
 * Baca file CSV menjadi array baris (mirip output SimpleXlsxReader::readSheet)
 */
function stockImportReadCsv(string $path): array
{
    $rows = [];
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new Exception("File CSV tidak dapat dibuka.");
    }

    // deteksi delimiter dari baris pertama (koma vs titik koma)
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rows[] = array_map('trim', $data);
    }
    fclose($handle);
    return $rows;
}

/**
 * Proses import stok utama.
 *
 * @return array{total_baris:int, berhasil:int, gagal:int, duplikat:int, errors:array<int,string>}
 */
function processStockImport(PDO $conn, string $tmpPath, string $originalName, int $id_kategori, string $nama_kategori, int $user_id): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        $rows = SimpleXlsxReader::readSheet($tmpPath);
    } elseif ($ext === 'csv') {
        $rows = stockImportReadCsv($tmpPath);
    } else {
        throw new Exception("Format file tidak didukung. Gunakan file .csv atau .xlsx.");
    }

    // cari baris header: baris pertama yang punya minimal kolom nama_barang & (stok_awal/satuan)
    $headerRowIdx = null;
    $colMap = [];
    foreach ($rows as $i => $row) {
        $candidate = stockImportMapHeader($row);
        if (isset($candidate['nama_barang']) && (isset($candidate['stok_awal']) || isset($candidate['satuan']))) {
            $headerRowIdx = $i;
            $colMap = $candidate;
            break;
        }
    }

    if ($headerRowIdx === null) {
        throw new Exception("Header kolom tidak dikenali. Pastikan file memiliki kolom 'Nama Barang' dan 'Volume/Stok Awal'.");
    }

    $dataRows = array_slice($rows, $headerRowIdx + 1);

    $totalBaris = 0;
    $berhasil = 0;
    $gagal = 0;
    $duplikat = 0;
    $errors = [];

    // 1. Pastikan jenis_barang_gudang untuk kategori ini ada (satu jenis umum per kategori,
    //    konsisten dengan alur "Tambah Barang Baru" yang sudah ada di aplikasi)
    $stmtJenis = $conn->prepare("SELECT id_jenis FROM jenis_barang_gudang WHERE id_kategori = :id_kategori AND nama_jenis = :nama LIMIT 1");
    $stmtJenis->execute(['id_kategori' => $id_kategori, 'nama' => $nama_kategori]);
    $jenis = $stmtJenis->fetch();
    if ($jenis) {
        $id_jenis = $jenis['id_jenis'];
    } else {
        $stmtInsJenis = $conn->prepare("INSERT INTO jenis_barang_gudang (id_kategori, nama_jenis) VALUES (:id_kategori, :nama)");
        $stmtInsJenis->execute(['id_kategori' => $id_kategori, 'nama' => $nama_kategori]);
        $id_jenis = $conn->lastInsertId();
    }

    $today = date('Y-m-d');
    $rowNoInFile = $headerRowIdx + 1; // untuk pesan error, 1-based termasuk header

    foreach ($dataRows as $row) {
        $rowNoInFile++;

        $namaBarang = trim($row[$colMap['nama_barang']] ?? '');
        if ($namaBarang === '') {
            continue; // baris kosong, lewati diam-diam (bukan error)
        }
        $totalBaris++;

        try {
            $kodeBarang = isset($colMap['kode_barang']) ? trim($row[$colMap['kode_barang']] ?? '') : '';
            $satuan = isset($colMap['satuan']) ? trim($row[$colMap['satuan']] ?? '') : 'Pcs';
            if ($satuan === '') {
                $satuan = 'Pcs';
            }

            $stokAwalRaw = isset($colMap['stok_awal']) ? trim((string) ($row[$colMap['stok_awal']] ?? '')) : '';
            $stokAwal = is_numeric($stokAwalRaw) ? (int) round((float) $stokAwalRaw) : 0;

            $pemakaianRaw = isset($colMap['pemakaian']) ? trim((string) ($row[$colMap['pemakaian']] ?? '')) : '';
            $pemakaian = is_numeric($pemakaianRaw) ? (int) round((float) $pemakaianRaw) : 0;

            $hargaRaw = isset($colMap['harga_satuan']) ? trim((string) ($row[$colMap['harga_satuan']] ?? '')) : '';
            $hargaSatuan = is_numeric($hargaRaw) ? (float) $hargaRaw : null;

            $sisaStok = $stokAwal - $pemakaian;

            // auto-generate kode barang kalau kosong (format "1.x/2.x/3.x/4.x" sesuai kategori)
            if ($kodeBarang === '') {
                $kodeBarang = stockGenerateKodeBarang($conn, $id_kategori, $nama_kategori);
            }

            // Cari barang existing: berdasarkan kode_barang dulu, lalu fallback nama+jenis
            $existing = null;
            if ($kodeBarang !== '') {
                $stmtCek = $conn->prepare("SELECT id FROM Gudang_Stok WHERE kode_barang = :kode LIMIT 1");
                $stmtCek->execute(['kode' => $kodeBarang]);
                $existing = $stmtCek->fetch();
            }
            if (!$existing) {
                $stmtCekNama = $conn->prepare("SELECT id FROM Gudang_Stok WHERE nama_barang = :nama AND id_jenis = :id_jenis LIMIT 1");
                $stmtCekNama->execute(['nama' => $namaBarang, 'id_jenis' => $id_jenis]);
                $existing = $stmtCekNama->fetch();
            }

            if ($existing) {
                // update (opname ulang / refresh data): reset periode stok_awal
                $stmtUpd = $conn->prepare("UPDATE Gudang_Stok
                    SET nama_barang = :nama, satuan = :satuan, stok_awal = :stok_awal,
                        stok_sistem = :sisa, tgl_opname_awal = :tgl,
                        harga_satuan = COALESCE(:harga, harga_satuan)
                    WHERE id = :id");
                $stmtUpd->execute([
                    'nama' => $namaBarang,
                    'satuan' => $satuan,
                    'stok_awal' => $stokAwal,
                    'sisa' => $sisaStok,
                    'tgl' => $today,
                    'harga' => $hargaSatuan,
                    'id' => $existing['id'],
                ]);
                $barangId = $existing['id'];
                $duplikat++;
            } else {
                $stmtIns = $conn->prepare("INSERT INTO Gudang_Stok
                    (kode_barang, nama_barang, id_jenis, satuan, stok_sistem, stok_awal, tgl_opname_awal, harga_satuan)
                    VALUES (:kode, :nama, :id_jenis, :satuan, :sisa, :stok_awal, :tgl, :harga)");
                $stmtIns->execute([
                    'kode' => $kodeBarang,
                    'nama' => $namaBarang,
                    'id_jenis' => $id_jenis,
                    'satuan' => $satuan,
                    'sisa' => $sisaStok,
                    'stok_awal' => $stokAwal,
                    'tgl' => $today,
                    'harga' => $hargaSatuan,
                ]);
                $barangId = $conn->lastInsertId();
            }

            // catat pemakaian dari file (jika ada) sebagai mutasi Keluar agar tercatat di histori
            if ($pemakaian > 0) {
                $stmtMut = $conn->prepare("INSERT INTO Mutasi_Stok (barang_id, jenis_mutasi, jumlah, pemakai, tanggal, keterangan, dibuat_oleh)
                    VALUES (:barang_id, 'Keluar', :jumlah, NULL, :tanggal, 'Import Stock Opname', :user_id)");
                $stmtMut->execute([
                    'barang_id' => $barangId,
                    'jumlah' => $pemakaian,
                    'tanggal' => $today,
                    'user_id' => $user_id,
                ]);
            }

            $berhasil++;
        } catch (Exception $e) {
            $gagal++;
            $errors[] = "Baris {$rowNoInFile} ({$namaBarang}): " . $e->getMessage();
        }
    }

    // catat ke import_log
    $stmtLog = $conn->prepare("INSERT INTO import_log (nama_file, total_baris, berhasil, gagal, duplikat, detail_error, diupload_oleh)
        VALUES (:nama_file, :total, :berhasil, :gagal, :duplikat, :error, :user_id)");
    $stmtLog->execute([
        'nama_file' => $originalName,
        'total' => $totalBaris,
        'berhasil' => $berhasil,
        'gagal' => $gagal,
        'duplikat' => $duplikat,
        'error' => empty($errors) ? null : implode("\n", $errors),
        'user_id' => $user_id,
    ]);

    return [
        'total_baris' => $totalBaris,
        'berhasil' => $berhasil,
        'gagal' => $gagal,
        'duplikat' => $duplikat,
        'errors' => $errors,
    ];
}