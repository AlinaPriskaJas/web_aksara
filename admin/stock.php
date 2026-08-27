<?php
// admin/stock.php — Modul Gudang: Stock Opname per kategori (tab dinamis sesuai Excel)
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
$active_tab = 'tabKat1';

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
        $active_tab = 'tabImportResult';
        $id_kategori = intval($_POST['id_kategori_import'] ?? 0);

        if ($id_kategori <= 0 || empty($_FILES['file_import']['name'])) {
            $error_msg = "Pilih kategori dan file (.csv / .xlsx) terlebih dahulu!";
        } else {
            try {
                $stmtKat = $conn->prepare("SELECT nama_kategori FROM kategori_barang_gudang WHERE id_kategori = :id");
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
        $kode_barang = trim($_POST['kode_barang']);
        $nama_barang = trim($_POST['nama_barang']);
        $kategori_pilih = trim($_POST['kategori_pilih']);
        $kategori_manual = trim($_POST['kategori_manual'] ?? '');
        $satuan = trim($_POST['satuan']);
        $stok_awal = intval($_POST['stok_awal']);
        $stok_minimum = (!empty($_POST['stok_minimum'])) ? intval($_POST['stok_minimum']) : null;
        $lokasi_rak = trim($_POST['lokasi_rak']);
        $harga_satuan = (!empty($_POST['harga_satuan'])) ? floatval($_POST['harga_satuan']) : null;

        $nama_kategori = ($kategori_pilih === 'Lainnya') ? $kategori_manual : $kategori_pilih;

        if (empty($kode_barang) || empty($nama_barang) || empty($nama_kategori) || empty($satuan)) {
            $error_msg = "Semua field wajib (Kode, Nama Barang, Kategori, Satuan) harus diisi!";
        } else {
            try {
                $conn->beginTransaction();

                $stmtCekKat = $conn->prepare("SELECT id_kategori FROM kategori_barang_gudang WHERE nama_kategori = :nama");
                $stmtCekKat->execute(['nama' => $nama_kategori]);
                $kat = $stmtCekKat->fetch();

                if ($kat) {
                    $id_kategori = $kat['id_kategori'];
                } else {
                    $stmtInsKat = $conn->prepare("INSERT INTO kategori_barang_gudang (nama_kategori) VALUES (:nama)");
                    $stmtInsKat->execute(['nama' => $nama_kategori]);
                    $id_kategori = $conn->lastInsertId();
                }

                $stmtCekJenis = $conn->prepare("SELECT id_jenis FROM jenis_barang_gudang WHERE id_kategori = :id_kategori AND nama_jenis = :nama");
                $stmtCekJenis->execute(['id_kategori' => $id_kategori, 'nama' => $nama_kategori]);
                $jenis = $stmtCekJenis->fetch();

                if ($jenis) {
                    $id_jenis = $jenis['id_jenis'];
                } else {
                    $stmtInsJenis = $conn->prepare("INSERT INTO jenis_barang_gudang (id_kategori, nama_jenis) VALUES (:id_kategori, :nama)");
                    $stmtInsJenis->execute(['id_kategori' => $id_kategori, 'nama' => $nama_kategori]);
                    $id_jenis = $conn->lastInsertId();
                }

                $stmtCekKode = $conn->prepare("SELECT id FROM Gudang_Stok WHERE kode_barang = :kode");
                $stmtCekKode->execute(['kode' => $kode_barang]);
                if ($stmtCekKode->fetch()) {
                    throw new Exception("Kode Barang/SKU '$kode_barang' sudah digunakan, gunakan kode lain.");
                }

                $stmtInsBarang = $conn->prepare("INSERT INTO Gudang_Stok
                    (kode_barang, nama_barang, id_jenis, satuan, stok_sistem, stok_awal, tgl_opname_awal, lokasi_rak, stok_minimum, harga_satuan)
                    VALUES (:kode, :nama, :id_jenis, :satuan, :stok, :stok_awal, :tgl, :rak, :min, :harga)");
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
                $active_tab = 'tabKatByName:' . $nama_kategori;
            } catch (Exception $e) {
                $conn->rollBack();
                $error_msg = "Gagal menambah barang: " . $e->getMessage();
            }
        }
    }

    // ---- 3. Form Penggunaan Barang (Pemakaian / Keluar) ----
    if (isset($_POST['action']) && $_POST['action'] === 'pemakaian') {
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

                $stmtUpd = $conn->prepare("UPDATE Gudang_Stok SET stok_sistem = stok_sistem + :jumlah WHERE id = :barang_id");
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

    // ---- 5. Edit Barang (satuan, rak, stok minimum, harga satuan) ----
    if (isset($_POST['action']) && $_POST['action'] === 'edit_barang') {
        $barang_id = intval($_POST['barang_id']);
        $satuan = trim($_POST['satuan']);
        $lokasi_rak = trim($_POST['lokasi_rak']);
        $stok_minimum = (!empty($_POST['stok_minimum'])) ? intval($_POST['stok_minimum']) : null;
        $harga_satuan = (!empty($_POST['harga_satuan'])) ? floatval($_POST['harga_satuan']) : null;

        try {
            $stmt = $conn->prepare("UPDATE Gudang_Stok SET satuan = :satuan, lokasi_rak = :rak, stok_minimum = :min, harga_satuan = :harga WHERE id = :id");
            $stmt->execute([
                'satuan' => $satuan,
                'rak' => $lokasi_rak,
                'min' => $stok_minimum,
                'harga' => $harga_satuan,
                'id' => $barang_id,
            ]);
            catatAudit($conn, 'Gudang', 'Edit Barang', "Mengubah data barang #{$barang_id}", null, $_POST);
            $success_msg = "Data barang berhasil diperbarui.";
        } catch (Exception $e) {
            $error_msg = "Gagal memperbarui barang: " . $e->getMessage();
        }
    }

    // ---- 6. Reset Periode Opname (per barang / per kategori) ----
    if (isset($_POST['action']) && $_POST['action'] === 'reset_opname_item') {
        $barang_id = intval($_POST['barang_id']);
        try {
            $stmt = $conn->prepare("UPDATE Gudang_Stok SET stok_awal = stok_sistem, tgl_opname_awal = CURDATE() WHERE id = :id");
            $stmt->execute(['id' => $barang_id]);
            catatAudit($conn, 'Gudang', 'Reset Opname', "Reset periode opname barang #{$barang_id}");
            $success_msg = "Periode opname barang berhasil direset. Stok awal baru = sisa stok saat ini.";
        } catch (Exception $e) {
            $error_msg = "Gagal reset opname: " . $e->getMessage();
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'reset_opname_kategori') {
        $id_kategori = intval($_POST['id_kategori']);
        try {
            $stmt = $conn->prepare("UPDATE Gudang_Stok gs
                JOIN jenis_barang_gudang jb ON gs.id_jenis = jb.id_jenis
                SET gs.stok_awal = gs.stok_sistem, gs.tgl_opname_awal = CURDATE()
                WHERE jb.id_kategori = :id_kategori");
            $stmt->execute(['id_kategori' => $id_kategori]);
            catatAudit($conn, 'Gudang', 'Reset Opname Kategori', "Reset periode opname untuk kategori #{$id_kategori}");
            $success_msg = "Periode opname untuk seluruh barang di kategori ini berhasil direset.";
        } catch (Exception $e) {
            $error_msg = "Gagal reset opname kategori: " . $e->getMessage();
        }
    }
}

// ============================== FETCH DATA ==============================

// Kategori dasar untuk dropdown "Tambah Barang" (tetap ada opsi Lainnya)
$kategori_dasar = ['ATK', 'AAK3', 'Konsumsi', 'Kebersihan'];
$kategori_custom_rows = $conn->query("SELECT nama_kategori FROM kategori_barang_gudang ORDER BY nama_kategori ASC")->fetchAll();
$kategori_custom = [];
foreach ($kategori_custom_rows as $row) {
    if (!in_array($row['nama_kategori'], $kategori_dasar)) {
        $kategori_custom[] = $row['nama_kategori'];
    }
}
$kategori_options = array_merge($kategori_dasar, $kategori_custom, ['Lainnya']);

// Semua kategori (ini yang jadi TAB di halaman, urut sesuai id_kategori)
$kategoris = $conn->query("SELECT * FROM kategori_barang_gudang ORDER BY id_kategori ASC")->fetchAll();

// Item per kategori, lengkap dengan pemakaian bulan berjalan (dihitung otomatis dari Mutasi_Stok)
$itemsByKategori = [];
$stmtItems = $conn->prepare("
    SELECT gs.*, jbg.nama_jenis,
        (SELECT COALESCE(SUM(ms.jumlah), 0) FROM Mutasi_Stok ms
            WHERE ms.barang_id = gs.id AND ms.jenis_mutasi = 'Keluar'
            AND ms.tanggal >= COALESCE(gs.tgl_opname_awal, '1970-01-01')) AS pemakaian
    FROM Gudang_Stok gs
    JOIN jenis_barang_gudang jbg ON gs.id_jenis = jbg.id_jenis
    WHERE jbg.id_kategori = :id_kategori
    ORDER BY gs.nama_barang ASC
");
foreach ($kategoris as $kat) {
    $stmtItems->execute(['id_kategori' => $kat['id_kategori']]);
    $itemsByKategori[$kat['id_kategori']] = $stmtItems->fetchAll();
}

// Semua barang (untuk select di modal Barang Masuk, dikelompokkan per kategori)
$semuaBarang = $conn->query("
    SELECT gs.id, gs.nama_barang, gs.satuan, gs.stok_sistem, kbg.nama_kategori
    FROM Gudang_Stok gs
    JOIN jenis_barang_gudang jbg ON gs.id_jenis = jbg.id_jenis
    JOIN kategori_barang_gudang kbg ON jbg.id_kategori = kbg.id_kategori
    ORDER BY kbg.nama_kategori ASC, gs.nama_barang ASC
")->fetchAll();

// Log Barang Masuk
$barangMasukList = $conn->query("
    SELECT ms.*, gs.nama_barang, gs.kode_barang, gs.satuan, kbg.nama_kategori, u.nama_lengkap AS operator
    FROM Mutasi_Stok ms
    JOIN Gudang_Stok gs ON ms.barang_id = gs.id
    JOIN jenis_barang_gudang jbg ON gs.id_jenis = jbg.id_jenis
    JOIN kategori_barang_gudang kbg ON jbg.id_kategori = kbg.id_kategori
    JOIN Users u ON ms.dibuat_oleh = u.id
    WHERE ms.jenis_mutasi = 'Masuk'
    ORDER BY ms.tanggal DESC, ms.id DESC LIMIT 200
")->fetchAll();

// Log Transaksi (Pemakaian / Keluar)
$transaksiList = $conn->query("
    SELECT ms.*, gs.nama_barang, gs.kode_barang, gs.satuan, kbg.nama_kategori, u.nama_lengkap AS operator
    FROM Mutasi_Stok ms
    JOIN Gudang_Stok gs ON ms.barang_id = gs.id
    JOIN jenis_barang_gudang jbg ON gs.id_jenis = jbg.id_jenis
    JOIN kategori_barang_gudang kbg ON jbg.id_kategori = kbg.id_kategori
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

// Tentukan tab aktif kalau diarahkan lewat nama kategori (setelah tambah barang)
if (strpos($active_tab, 'tabKatByName:') === 0) {
    $namaCari = substr($active_tab, strlen('tabKatByName:'));
    $active_tab = 'tabKat1';
    foreach ($kategoris as $kat) {
        if ($kat['nama_kategori'] === $namaCari) {
            $active_tab = 'tabKat' . $kat['id_kategori'];
            break;
        }
    }
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
                <button class="btn-secondary-custom" onclick="openModal('modalBarangMasuk')">
                    <i class="bi bi-box-arrow-in-down"></i> Catat Barang Masuk
                </button>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav" style="flex-wrap:wrap;">
            <?php foreach ($kategoris as $kat): ?>
                <button type="button"
                    class="arp-tab-btn<?= $active_tab === 'tabKat' . $kat['id_kategori'] ? ' active' : '' ?>"
                    data-tab-target="tabKat<?= $kat['id_kategori'] ?>"
                    onclick="switchTab('tabKat<?= $kat['id_kategori'] ?>', this)">
                    <i class="bi <?= stockKategoriIcon($kat['nama_kategori']) ?> me-1"></i>
                    <?= htmlspecialchars($kat['nama_kategori']) ?>
                    <span class="badge-secondary ms-1"><?= count($itemsByKategori[$kat['id_kategori']]) ?></span>
                </button>
            <?php endforeach; ?>
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
            <!-- ===================== TAB PER KATEGORI ===================== -->
            <?php foreach ($kategoris as $kat):
                $kid = $kat['id_kategori'];
                $rowsKat = $itemsByKategori[$kid];
                $tableId = 'tabelStok' . $kid;
                ?>
                <div class="col-12 arp-tab-panel" id="tabKat<?= $kid ?>" <?= $active_tab === 'tabKat' . $kid ? '' : 'style="display:none;"' ?>>
                    <div class="card-box">
                        <div class="table-toolbar">
                            <h5 class="table-toolbar-title fw-bold">Stok — <?= htmlspecialchars($kat['nama_kategori']) ?>
                            </h5>
                            <div class="table-toolbar-actions">
                                <div class="search-box-container">
                                    <i class="bi bi-search"></i>
                                    <input type="text" class="search-box" placeholder="Cari barang..."
                                        data-table-search="<?= $tableId ?>" onkeyup="handleTableSearch('<?= $tableId ?>')">
                                </div>
                                <button class="btn-secondary-custom"
                                    onclick="openModalTambah('<?= htmlspecialchars($kat['nama_kategori'], ENT_QUOTES) ?>')">
                                    <i class="bi bi-plus-lg"></i> Tambah Barang
                                </button>
                                <button class="btn-primary-custom" onclick="openModal('modalPemakaian<?= $kid ?>')">
                                    <i class="bi bi-box-arrow-up"></i> Form Penggunaan Barang
                                </button>
                                <form method="POST" action="stock.php" class="d-inline"
                                    onsubmit="return confirm('Reset periode opname untuk semua barang di kategori ini? Stok awal akan disamakan dengan sisa stok saat ini.');">
                                    <input type="hidden" name="action" value="reset_opname_kategori">
                                    <input type="hidden" name="id_kategori" value="<?= $kid ?>">
                                    <button type="submit" class="btn-secondary-custom" title="Mulai periode opname baru">
                                        <i class="bi bi-arrow-repeat"></i> Reset Opname
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="table-responsive-custom">
                            <table class="table-custom" id="<?= $tableId ?>">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Nama Barang</th>
                                        <th>Stok Awal</th>
                                        <th>Pemakaian</th>
                                        <th>Sisa Stok</th>
                                        <th>Satuan</th>
                                        <th>Harga Satuan</th>
                                        <th>Status</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($rowsKat) === 0): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">Belum ada barang di
                                                kategori ini. Tambah manual atau import dari file Excel/CSV.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($rowsKat as $it): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($it['kode_barang']) ?></strong></td>
                                                <td><?= htmlspecialchars($it['nama_barang']) ?></td>
                                                <td><?= (int) $it['stok_awal'] ?></td>
                                                <td><span
                                                        class="badge-warning"><?= (int) $it['pemakaian'] ?></span>
                                                </td>
                                                <td><strong><?= (int) $it['stok_sistem'] ?></strong></td>
                                                <td><?= htmlspecialchars($it['satuan']) ?></td>
                                                <td><?= $it['harga_satuan'] !== null ? 'Rp ' . number_format($it['harga_satuan'], 0, ',', '.') : '-' ?>
                                                </td>
                                                <td>
                                                    <?php if ($it['stok_minimum'] !== null && $it['stok_sistem'] <= $it['stok_minimum']): ?>
                                                        <span class="badge-danger">Stok Tipis</span>
                                                    <?php else: ?>
                                                        <span class="badge-success">Aman</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn-secondary-custom py-1 px-2"
                                                        onclick='openModalEdit(<?= json_encode([
                                                            "id" => $it["id"],
                                                            "nama" => $it["nama_barang"],
                                                            "satuan" => $it["satuan"],
                                                            "rak" => $it["lokasi_rak"],
                                                            "min" => $it["stok_minimum"],
                                                            "harga" => $it["harga_satuan"],
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
                        <div class="pagination-custom" id="pagination-<?= $tableId ?>"></div>
                    </div>
                </div>

                <!-- Modal Form Penggunaan Barang khusus kategori ini -->
                <div class="arp-modal-overlay" id="modalPemakaian<?= $kid ?>"
                    onclick="closeModalOutside(event, 'modalPemakaian<?= $kid ?>')">
                    <div class="arp-modal-box" style="max-width:520px;">
                        <div class="arp-modal-header">
                            <div>
                                <h5 class="fw-bold mb-0">Form Penggunaan Barang — <?= htmlspecialchars($kat['nama_kategori']) ?>
                                </h5>
                                <small class="text-muted">Catat pemakaian, sisa stok terupdate otomatis</small>
                            </div>
                            <button class="arp-modal-close"
                                onclick="closeModal('modalPemakaian<?= $kid ?>')">&times;</button>
                        </div>
                        <div class="arp-modal-body">
                            <form method="POST" action="stock.php">
                                <input type="hidden" name="action" value="pemakaian">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold mb-2">Pilih Barang *</label>
                                    <select name="barang_id" class="select-custom" required>
                                        <option value="">-- Pilih Barang --</option>
                                        <?php foreach ($rowsKat as $it): ?>
                                            <option value="<?= $it['id'] ?>">
                                                <?= htmlspecialchars($it['nama_barang']) ?>
                                                (Sisa: <?= (int) $it['stok_sistem'] ?> <?= htmlspecialchars($it['satuan']) ?>)
                                            </option>
                                        <?php endforeach; ?>
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
                                        <input type="date" name="tanggal" class="form-control-custom"
                                            value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold mb-2">Nama Pemakai *</label>
                                    <input type="text" name="pemakai" class="form-control-custom"
                                        placeholder="Contoh: Ayu" required>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-semibold mb-2">Keterangan</label>
                                    <textarea name="keterangan" class="textarea-custom"
                                        placeholder="Contoh: kebutuhan kantor / nama klien"></textarea>
                                </div>
                                <div class="d-flex gap-2 justify-content-end">
                                    <button type="button" class="btn-secondary-custom"
                                        onclick="closeModal('modalPemakaian<?= $kid ?>')">Batal</button>
                                    <button type="submit" class="btn-primary-custom">Catat Pemakaian</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- ===================== TAB BARANG MASUK ===================== -->
            <div class="col-12 arp-tab-panel" id="tabBarangMasuk" <?= $active_tab === 'tabBarangMasuk' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Riwayat Barang Masuk (Pembelian)</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari barang masuk..."
                                    data-table-search="tabelBarangMasuk" onkeyup="handleTableSearch('tabelBarangMasuk')">
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
                                        <td colspan="7" class="text-center py-4 text-muted">Belum ada riwayat barang
                                            masuk.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($barangMasukList as $bm): ?>
                                        <tr>
                                            <td><?= date('d-m-Y', strtotime($bm['tanggal'])) ?></td>
                                            <td><?= htmlspecialchars($bm['kode_barang']) ?></td>
                                            <td><strong><?= htmlspecialchars($bm['nama_barang']) ?></strong></td>
                                            <td><span class="badge-secondary"><?= htmlspecialchars($bm['nama_kategori']) ?></span>
                                            </td>
                                            <td><?= (int) $bm['jumlah'] ?> <?= htmlspecialchars($bm['satuan']) ?></td>
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
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelTransaksi">
                            <thead>
                                <tr>
                                    <th>No</th>
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
                                        <td colspan="7" class="text-center py-4 text-muted">Belum ada transaksi
                                            pemakaian.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php $no = 1;
                                    foreach ($transaksiList as $tr): ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td><strong><?= htmlspecialchars($tr['nama_barang']) ?></strong></td>
                                            <td><span class="badge-secondary"><?= htmlspecialchars($tr['nama_kategori']) ?></span>
                                            </td>
                                            <td><?= date('d-m-Y', strtotime($tr['tanggal'])) ?></td>
                                            <td><?= (int) $tr['jumlah'] ?> <?= htmlspecialchars($tr['satuan']) ?></td>
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
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Ringkasan Nilai Stok &amp; Keuangan</h5>
                        <div class="table-toolbar-actions">
                            <small class="text-muted">Dihitung otomatis dari Harga Satuan × Jumlah barang.
                                Lengkapi Harga Satuan lewat tombol edit di masing-masing tab kategori.</small>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelKeuangan">
                            <thead>
                                <tr>
                                    <th>Kategori</th>
                                    <th>Jumlah Item</th>
                                    <th>Nilai Stok Awal (Rp)</th>
                                    <th>Nilai Pemakaian Bulan Ini (Rp)</th>
                                    <th>Nilai Sisa Stok (Rp)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($keuangan as $k): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($k['nama']) ?></strong></td>
                                        <td><?= $k['jumlah_item'] ?></td>
                                        <td><?= 'Rp ' . number_format($k['nilai_stok_awal'], 0, ',', '.') ?></td>
                                        <td><span
                                                class="badge-warning">Rp <?= number_format($k['nilai_pemakaian'], 0, ',', '.') ?></span>
                                        </td>
                                        <td><strong>Rp <?= number_format($k['nilai_sisa_stok'], 0, ',', '.') ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="font-weight:700;background:rgba(0,0,0,.02);">
                                    <td>TOTAL</td>
                                    <td><?= $grandTotal['item'] ?></td>
                                    <td>Rp <?= number_format($grandTotal['stok_awal'], 0, ',', '.') ?></td>
                                    <td>Rp <?= number_format($grandTotal['pemakaian'], 0, ',', '.') ?></td>
                                    <td>Rp <?= number_format($grandTotal['sisa'], 0, ',', '.') ?></td>
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
                        <input type="file" name="file_import" class="form-control-custom" accept=".csv,.xlsx"
                            required>
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
                        <label class="form-label fw-semibold mb-2">Pilih Barang *</label>
                        <select name="barang_id" class="select-custom" required>
                            <option value="">-- Pilih Barang --</option>
                            <?php $lastKat = null;
                            foreach ($semuaBarang as $b):
                                if ($lastKat !== $b['nama_kategori']) {
                                    if ($lastKat !== null)
                                        echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($b['nama_kategori']) . '">';
                                    $lastKat = $b['nama_kategori'];
                                } ?>
                                <option value="<?= $b['id'] ?>">
                                    <?= htmlspecialchars($b['nama_barang']) ?>
                                    (Stok: <?= (int) $b['stok_sistem'] ?> <?= htmlspecialchars($b['satuan']) ?>)
                                </option>
                            <?php endforeach;
                            if ($lastKat !== null)
                                echo '</optgroup>'; ?>
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
                        <label class="form-label fw-semibold mb-2">Kode Barang / SKU *</label>
                        <input type="text" name="kode_barang" class="form-control-custom" placeholder="Contoh: ATK-0012"
                            required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Nama Barang Lengkap *</label>
                        <input type="text" name="nama_barang" class="form-control-custom"
                            placeholder="Contoh: Meteran Laser 50m" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Kategori Barang *</label>
                        <select name="kategori_pilih" id="kategoriPilih" class="select-custom"
                            onchange="toggleKategoriManual()" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategori_options as $kat): ?>
                                <option value="<?= htmlspecialchars($kat) ?>"><?= htmlspecialchars($kat) ?></option>
                            <?php endforeach; ?>
                            <option value="Lainnya">+ Lainnya (ketik / tambah kategori baru)</option>
                        </select>
                    </div>

                    <div class="mb-3" id="wrapperKategoriManual" style="display:none;">
                        <label class="form-label fw-semibold mb-2">Nama Kategori Baru *</label>
                        <input type="text" name="kategori_manual" id="kategoriManual" class="form-control-custom"
                            placeholder="Ketik nama kategori baru, contoh: APD Elektrikal">
                        <small class="text-muted">Kategori baru ini akan otomatis tersimpan dan muncul sebagai tab
                            baru.</small>
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
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Stok Minimum</label>
                            <input type="number" name="stok_minimum" class="form-control-custom" min="0"
                                placeholder="Contoh: 5">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Harga Satuan (Rp)</label>
                            <input type="number" name="harga_satuan" class="form-control-custom" min="0"
                                placeholder="Contoh: 15000">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Lokasi Penyimpanan / Rak</label>
                        <input type="text" name="lokasi_rak" class="form-control-custom"
                            placeholder="Contoh: Rak A-3, Gudang Lantai 2">
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
                    <small class="text-muted">Ubah satuan, rak, stok minimum &amp; harga satuan</small>
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
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Stok Minimum</label>
                            <input type="number" name="stok_minimum" id="editStokMin" class="form-control-custom"
                                min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Harga Satuan (Rp)</label>
                            <input type="number" name="harga_satuan" id="editHarga" class="form-control-custom" min="0">
                        </div>
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
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php foreach ($kategoris as $kat): ?>
            initTablePagination('tabelStok<?= $kat['id_kategori'] ?>', 10);
        <?php endforeach; ?>
        initTablePagination('tabelBarangMasuk', 10);
        initTablePagination('tabelTransaksi', 10);
    });

    function toggleKategoriManual() {
        const pilih = document.getElementById('kategoriPilih').value;
        const wrapper = document.getElementById('wrapperKategoriManual');
        const inputManual = document.getElementById('kategoriManual');
        if (pilih === 'Lainnya') {
            wrapper.style.display = 'block';
            inputManual.required = true;
        } else {
            wrapper.style.display = 'none';
            inputManual.required = false;
            inputManual.value = '';
        }
    }

    // Buka modal Tambah Barang dengan kategori sudah terpilih sesuai tab asal
    function openModalTambah(namaKategori) {
        const select = document.getElementById('kategoriPilih');
        if (select) {
            let found = false;
            for (const opt of select.options) {
                if (opt.value === namaKategori) { found = true; break; }
            }
            select.value = found ? namaKategori : '';
            toggleKategoriManual();
        }
        openModal('modalTambahBarang');
    }

    // Buka modal Edit Barang dan isi field dari data barang yang diklik
    function openModalEdit(data) {
        document.getElementById('editBarangNama').textContent = 'Edit — ' + data.nama;
        document.getElementById('editBarangId').value = data.id;
        document.getElementById('editSatuan').value = data.satuan || '';
        document.getElementById('editStokMin').value = data.min ?? '';
        document.getElementById('editHarga').value = data.harga ?? '';
        document.getElementById('editRak').value = data.rak || '';
        openModal('modalEditBarang');
    }
</script>

<?php
include "../includes/footer.php";
?>
