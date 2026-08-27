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

            // auto-generate kode barang kalau kosong
            if ($kodeBarang === '') {
                $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $nama_kategori), 0, 3));
                $kodeBarang = $prefix . '-' . substr(md5($namaBarang . microtime()), 0, 6);
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
