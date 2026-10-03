<?php
// ahli_k3/upload.php — Alur Pemeriksaan (Pemeriksaan -> Diproses -> Selesai)

require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$pdo = $conn;
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once "../includes/functions.php";
require_once "../includes/drive_helper.php";
require_once "../includes/laporan_helper.php";      // dipakai untuk penomoran laporan
require_once "../includes/pemeriksaan_helper.php";

$page_title = "Pemeriksaan";
$current_user_id = (int) $_SESSION['user_id'];

jp_self_heal($pdo);   // pastikan kolom baru ada

/** Template pertama (urut nama) untuk kombinasi bidang + unit -> kode laporan, atau null. */
function jpKodeTemplate(PDO $pdo, int $idKategori, int $idJenis): ?string
{
    $st = $pdo->prepare("SELECT kode_laporan FROM Template_Laporan
                         WHERE id_kategori = ? AND id_jenis = ? AND drive_file_id IS NOT NULL
                         ORDER BY nama ASC LIMIT 1");
    $st->execute([$idKategori, $idJenis]);
    $kode = $st->fetchColumn();
    return $kode ? (string) $kode : null;
}

// ==========================================
// [AJAX] Autocomplete perusahaan
// ==========================================
if (($_GET['ajax'] ?? '') === 'cari_klien') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $hasil = [];
    if (mb_strlen($q) >= 1) {
        $stmt = $pdo->prepare("SELECT id, nama_perusahaan, alamat FROM Data_Klien WHERE nama_perusahaan LIKE ? ORDER BY nama_perusahaan ASC LIMIT 15");
        $stmt->execute(['%' . $q . '%']);
        $hasil = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($hasil);
    exit;
}

// ==========================================
// [AJAX] Unit objek per bidang
// ==========================================
if (($_GET['ajax'] ?? '') === 'unit_objek_by_bidang') {
    header('Content-Type: application/json');
    $idKategori = (int) ($_GET['id_kategori'] ?? 0);
    $hasil = [];
    if ($idKategori > 0) {
        $stmt = $pdo->prepare("SELECT id_jenis, nama_objek FROM Jenis_Objek_K3 WHERE id_kategori = ? ORDER BY nama_objek ASC");
        $stmt->execute([$idKategori]);
        $hasil = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($hasil);
    exit;
}

// ==========================================
// [AJAX] Info alat: field yang tampil + nomor laporan berikutnya
// ==========================================
if (($_GET['ajax'] ?? '') === 'info_alat') {
    header('Content-Type: application/json');
    $idKategori = (int) ($_GET['id_kategori'] ?? 0);
    $idJenis = (int) ($_GET['id_jenis'] ?? 0);
    $urut = '';
    $suffix = '';
    $kode = ($idKategori > 0 && $idJenis > 0) ? jpKodeTemplate($pdo, $idKategori, $idJenis) : null;
    if ($kode) {
        $bagian = explode('/', lp_preview_nomor($pdo, $kode));
        $urut = $bagian[0];
        $suffix = '/' . implode('/', array_slice($bagian, 1));
    }
    echo json_encode([
        'fields' => jp_field_tampil_untuk($pdo, $idKategori, $idJenis),
        'urut' => $urut,
        'suffix' => $suffix,
        'ada_template' => $kode !== null,
    ]);
    exit;
}

// ==========================================
// Tab aktif & flash
// ==========================================
$tabMap = [
    'pemeriksaan' => 'tabPanelPemeriksaan',
    'proses' => 'tabPanelProses',
    'selesai' => 'tabPanelSelesai',
];
$active_tab = $tabMap[$_GET['tab'] ?? 'pemeriksaan'] ?? 'tabPanelPemeriksaan';

$flash = $_SESSION['flash_jp'] ?? null;
unset($_SESSION['flash_jp']);

function jpRedirect(string $tab = 'pemeriksaan'): void
{
    header('Location: upload.php?tab=' . urlencode($tab));
    exit;
}
function jpFlash(string $type, string $msg): void
{
    $_SESSION['flash_jp'] = ['type' => $type, 'msg' => $msg];
}

/** Ambil 1 pemeriksaan milik user ini (atau null). */
function jpAmbil(PDO $pdo, int $id, int $userId): ?array
{
    $st = $pdo->prepare("SELECT * FROM Proses_Pemeriksaan WHERE id = ? AND dibuat_oleh = ?");
    $st->execute([$id, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ==========================================
// [AKSI] Tambah pemeriksaan
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'tambah_pemeriksaan') {
    try {
        $namaPerusahaan = trim($_POST['nama_perusahaan'] ?? '');
        $namaAlat = trim($_POST['nama_alat'] ?? '');
        $klienId = (int) ($_POST['klien_id'] ?? 0);
        $idKategori = (int) ($_POST['id_kategori'] ?? 0);
        $idJenis = (int) ($_POST['id_jenis'] ?? 0);
        $noUrut = trim($_POST['no_urut'] ?? '');
        $jenisPemeriksaan = in_array($_POST['jenis_pemeriksaan'] ?? '', ['Pemeriksaan Baru', 'Pemeriksaan Berkala'], true)
            ? $_POST['jenis_pemeriksaan'] : 'Pemeriksaan Berkala';
        $tanggal = $_POST['tanggal_pemeriksaan'] ?? '';

        if ($namaPerusahaan === '' || $idKategori <= 0 || $idJenis <= 0) {
            throw new RuntimeException("Perusahaan, Bidang Objek, dan Unit Objek wajib diisi.");
        }
        $d = DateTime::createFromFormat('Y-m-d', $tanggal);
        if (!$d || $d->format('Y-m-d') !== $tanggal) {
            throw new RuntimeException("Tanggal pemeriksaan tidak valid.");
        }

        $cek = $pdo->prepare("SELECT COUNT(*) FROM Jenis_Objek_K3 WHERE id_jenis = ? AND id_kategori = ?");
        $cek->execute([$idJenis, $idKategori]);
        if ((int) $cek->fetchColumn() === 0) {
            throw new RuntimeException("Unit Objek tidak cocok dengan Bidang Objek yang dipilih.");
        }

        if ($klienId > 0) {
            $cekK = $pdo->prepare("SELECT COUNT(*) FROM Data_Klien WHERE id = ?");
            $cekK->execute([$klienId]);
            if ((int) $cekK->fetchColumn() === 0) {
                $klienId = 0;
            }
        }

        // Nomor laporan rencana (divalidasi: angka & belum dipakai)
        $nomorRencana = null;
        $kode = jpKodeTemplate($pdo, $idKategori, $idJenis);
        if ($kode) {
            $nomorRencana = lp_generate_nomor($pdo, $kode, $noUrut);
        }

        // Field template (ad = tunggal, ap = berpasangan), hanya yang ada di whitelist
        $ad = [];
        foreach ((array) ($_POST['ad'] ?? []) as $k => $v) {
            $v = trim((string) $v);
            if ($v !== '' && in_array($k, JP_FIELD_TAMPIL, true) && !isset(LP_FIELD_PASANGAN[$k])) {
                $ad[$k] = $v;
            }
        }
        $ap = [];
        foreach ((array) ($_POST['ap'] ?? []) as $k => $v) {
            if (!isset(LP_FIELD_PASANGAN[$k]) || !in_array($k, JP_FIELD_TAMPIL, true)) {
                continue;
            }
            $kiri = trim((string) ($v['kiri'] ?? ''));
            $kanan = trim((string) ($v['kanan'] ?? ''));
            if ($kiri !== '' || $kanan !== '') {
                $ap[$k] = ['kiri' => $kiri, 'kanan' => $kanan];
            }
        }
        $dataAlatJson = ($ad || $ap)
            ? json_encode(['dinamis' => $ad, 'pasangan' => $ap], JSON_UNESCAPED_UNICODE)
            : null;

        // Kolom lama: dari input lama (fallback) atau turunan field template
        $turun = jp_turunan_kolom_alat($ad, $ap);
        $alat = [];
        foreach (array_keys(JP_FIELD_ALAT) as $k) {
            $nilai = trim((string) ($_POST['alat'][$k] ?? ''));
            $alat[$k] = $nilai !== '' ? $nilai : ($turun[$k] ?? null);
        }

        $pdo->prepare("INSERT INTO Proses_Pemeriksaan
    (klien_id, nama_perusahaan, nama_alat, id_kategori, id_jenis, jenis_pemeriksaan, tanggal_pemeriksaan,
     nomor_rencana, lokasi, no_unit, no_seri, kapasitas, merk, tipe, tahun_alat, tempat_pembuatan,
     data_alat_json, status, dibuat_oleh)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'belum', ?)")
            ->execute([
                $klienId > 0 ? $klienId : null,
                $namaPerusahaan,
                $namaAlat !== '' ? $namaAlat : null,
                $idKategori,
                $idJenis,
                $jenisPemeriksaan,
                $tanggal,
                $nomorRencana,
                $alat['lokasi'],
                $alat['no_unit'],
                $alat['no_seri'],
                $alat['kapasitas'],
                $alat['merk'],
                $alat['tipe'],
                $alat['tahun_alat'],
                $alat['tempat_pembuatan'],
                $dataAlatJson,
                $current_user_id,
            ]);

        jpFlash('success', "Pemeriksaan untuk {$namaPerusahaan} berhasil ditambahkan.");
    } catch (Throwable $e) {
        jpFlash('error', 'Gagal menambah pemeriksaan: ' . $e->getMessage());
    }
    jpRedirect('pemeriksaan');
}

// ==========================================
// [AKSI] Buat Laporan -> status Diproses, lanjut ke form Buat Laporan
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'mulai_laporan') {
    $id = (int) ($_POST['pemeriksaan_id'] ?? 0);
    $jp = jpAmbil($pdo, $id, $current_user_id);
    if (!$jp) {
        jpFlash('error', 'Pemeriksaan tidak ditemukan.');
        jpRedirect('pemeriksaan');
    }
    if ($jp['status'] === 'selesai') {
        jpFlash('error', 'Pemeriksaan ini sudah selesai.');
        jpRedirect('selesai');
    }

    if (!empty($jp['laporan_id'])) {
        header('Location: pemeriksaan.php?tab=buat&edit_id=' . (int) $jp['laporan_id']);
        exit;
    }

    $pdo->prepare("UPDATE Proses_Pemeriksaan SET status = 'diproses' WHERE id = ? AND dibuat_oleh = ?")
        ->execute([$id, $current_user_id]);

    $query = [
        'tab' => 'buat',
        'id_kategori' => (int) $jp['id_kategori'],
        'id_jenis' => (int) $jp['id_jenis'],
        'pemeriksaan_id' => $id,
    ];
    $stT = $pdo->prepare("SELECT id FROM Template_Laporan WHERE id_kategori = ? AND id_jenis = ? AND drive_file_id IS NOT NULL");
    $stT->execute([(int) $jp['id_kategori'], (int) $jp['id_jenis']]);
    $tpls = $stT->fetchAll(PDO::FETCH_COLUMN);
    if (count($tpls) === 1) {
        $query['template_id'] = (int) $tpls[0];
    }

    header('Location: pemeriksaan.php?' . http_build_query($query));
    exit;
}

// ==========================================
// [AKSI] Selesai (tab Diproses -> tab Selesai)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'selesai_pemeriksaan') {
    try {
        $id = (int) ($_POST['pemeriksaan_id'] ?? 0);
        $jp = jpAmbil($pdo, $id, $current_user_id);
        if (!$jp || $jp['status'] !== 'diproses') {
            throw new RuntimeException("Pemeriksaan tidak ditemukan atau tidak sedang diproses.");
        }
        if (empty($jp['laporan_id'])) {
            throw new RuntimeException("Laporan belum disimpan. Selesaikan form Buat Laporan terlebih dahulu.");
        }
        $cekL = $pdo->prepare("SELECT COUNT(*) FROM Laporan_Pemeriksaan WHERE id = ?");
        $cekL->execute([(int) $jp['laporan_id']]);
        if ((int) $cekL->fetchColumn() === 0) {
            throw new RuntimeException("Laporan terkait tidak ditemukan.");
        }

        $pdo->prepare("UPDATE Proses_Pemeriksaan SET status = 'selesai', tanggal_selesai = NOW() WHERE id = ? AND dibuat_oleh = ?")
            ->execute([$id, $current_user_id]);
        jpFlash('success', 'Pemeriksaan ditandai selesai. Laporan tersedia di menu Laporan.');
        jpRedirect('selesai');
    } catch (Throwable $e) {
        jpFlash('error', $e->getMessage());
        jpRedirect('proses');
    }
}

// ==========================================
// [AKSI] Batalkan proses (hanya jika laporan belum disimpan)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'batal_proses') {
    $id = (int) ($_POST['pemeriksaan_id'] ?? 0);
    $jp = jpAmbil($pdo, $id, $current_user_id);
    if ($jp && $jp['status'] === 'diproses' && empty($jp['laporan_id'])) {
        $pdo->prepare("UPDATE Proses_Pemeriksaan SET status = 'belum' WHERE id = ? AND dibuat_oleh = ?")
            ->execute([$id, $current_user_id]);
        jpFlash('success', 'Proses dibatalkan. Pemeriksaan kembali ke daftar.');
    } else {
        jpFlash('error', 'Proses tidak bisa dibatalkan (laporan sudah disimpan atau data tidak ditemukan).');
    }
    jpRedirect('pemeriksaan');
}

// ==========================================
// [AKSI] Hapus pemeriksaan (hanya yang belum dibuat laporannya)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus_pemeriksaan') {
    $id = (int) ($_POST['pemeriksaan_id'] ?? 0);
    $jp = jpAmbil($pdo, $id, $current_user_id);
    if ($jp && $jp['status'] === 'belum') {
        $pdo->prepare("DELETE FROM Proses_Pemeriksaan WHERE id = ? AND dibuat_oleh = ?")->execute([$id, $current_user_id]);
        jpFlash('success', 'Pemeriksaan dihapus.');
    } else {
        jpFlash('error', 'Hanya pemeriksaan berstatus "Belum Dibuat" yang bisa dihapus.');
    }
    jpRedirect('pemeriksaan');
}

// ==========================================
// DATA
// ==========================================
$stmt = $pdo->prepare("
    SELECT jp.*, k.nama_kategori, j.nama_objek,
           lp.nomor_laporan, lp.file_laporan, lp.drive_file_id,
           lp.isi_data AS lp_isi_data, lp.tanggal_buat AS lp_tanggal_buat
    FROM Proses_Pemeriksaan jp
    JOIN Kategori_Objek_K3 k ON k.id_kategori = jp.id_kategori
    JOIN Jenis_Objek_K3 j ON j.id_jenis = jp.id_jenis
    LEFT JOIN Laporan_Pemeriksaan lp ON lp.id = jp.laporan_id
    WHERE jp.dibuat_oleh = ?
    ORDER BY jp.tanggal_pemeriksaan DESC, jp.id DESC
");
$stmt->execute([$current_user_id]);
$semua = $stmt->fetchAll(PDO::FETCH_ASSOC);

$listProses = array_values(array_filter($semua, fn($r) => $r['status'] === 'diproses'));
$listSelesai = array_values(array_filter($semua, fn($r) => $r['status'] === 'selesai'));

$daftar_kategori_objek = $pdo->query("SELECT id_kategori, nama_kategori FROM Kategori_Objek_K3 ORDER BY nama_kategori ASC")->fetchAll();

function jpBadgeStatus(string $status): string
{
    switch ($status) {
        case 'diproses':
            return '<span class="badge-primary">Diproses</span>';
        case 'selesai':
            return '<span class="badge-success">Selesai</span>';
        default:
            return '<span class="badge-warning">Belum Dibuat</span>';
    }
}

/** Nomor laporan: nomor asli jika laporan sudah ada, kalau belum nomor rencana. */
function jpNomor(array $r): string
{
    return (string) (($r['nomor_laporan'] ?? '') !== '' ? $r['nomor_laporan'] : ($r['nomor_rencana'] ?? '-')) ?: '-';
}

/** Lokasi: dari laporan (lokasi_unit) bila sudah ada, fallback data lama. */
function jpLokasi(array $r): string
{
    $isi = json_decode($r['lp_isi_data'] ?? '', true) ?: [];
    $lok = trim((string) ($isi['lokasi_unit'] ?? ''));
    if ($lok === '') {
        $lok = trim((string) ($r['lokasi'] ?? ''));
    }
    return $lok !== '' ? $lok : '-';
}

/** 7 sel data alat: No Unit s/d Tempat Pembuatan. */
function jpSelAlat(array $r): string
{
    $h = '';
    foreach (['no_unit', 'no_seri', 'kapasitas', 'merk', 'tipe', 'tahun_alat', 'tempat_pembuatan'] as $k) {
        $v = trim((string) ($r[$k] ?? ''));
        $h .= '<td>' . e($v !== '' ? $v : '-') . '</td>';
    }
    return $h;
}

function jpTgl(?string $t): string
{
    return !empty($t) ? date('d-m-Y', strtotime($t)) : '-';
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

    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelPemeriksaan' ? ' active' : '' ?>"
                data-tab-target="tabPanelPemeriksaan" data-tab-key="pemeriksaan"
                onclick="switchTab('tabPanelPemeriksaan', this)">
                <i class="bi bi-clipboard2-pulse me-1"></i> Pemeriksaan
                <span class="badge-warning ms-1"><?= count($semua) ?></span>
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelProses' ? ' active' : '' ?>"
                data-tab-target="tabPanelProses" data-tab-key="proses" onclick="switchTab('tabPanelProses', this)">
                <i class="bi bi-hourglass-split me-1"></i> Diproses
                <span class="badge-primary ms-1"><?= count($listProses) ?></span>
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelSelesai' ? ' active' : '' ?>"
                data-tab-target="tabPanelSelesai" data-tab-key="selesai" onclick="switchTab('tabPanelSelesai', this)">
                <i class="bi bi-check2-circle me-1"></i> Selesai
                <span class="badge-success ms-1"><?= count($listSelesai) ?></span>
            </button>
        </div>

        <!-- ============================== TAB 1: PEMERIKSAAN ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelPemeriksaan" <?= $active_tab === 'tabPanelPemeriksaan' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Pemeriksaan</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari perusahaan, unit, alat..."
                                data-table-search="tabelPemeriksaan" onkeyup="handleTableSearch('tabelPemeriksaan')">
                        </div>
                        <button class="btn-primary-custom" onclick="openModal('modalTambahPemeriksaan')">
                            <i class="bi bi-plus-lg"></i> Tambah Pemeriksaan
                        </button>
                    </div>
                </div>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelPemeriksaan" data-freeze-cols="3">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nomor Laporan</th>
                                <th>Nama Perusahaan</th>
                                <th>Bidang</th>
                                <th>Unit</th>
                                <th>Jenis Pemeriksaan</th>
                                <th>Nama Alat</th>
                                <th>Lokasi</th>
                                <th>Tanggal Pemeriksaan</th>
                                <th>No Unit</th>
                                <th>No Seri</th>
                                <th>Kapasitas</th>
                                <th>Merk</th>
                                <th>Tipe</th>
                                <th>Tahun Alat</th>
                                <th>Tempat Pembuatan Alat</th>
                                <th>Status</th>
                                <th class="col-aksi" style="text-align:center;">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($semua)): ?>
                                <tr>
                                    <td colspan="18" class="text-center py-4 text-muted">
                                        <i class="bi bi-clipboard-x d-block mb-2" style="font-size:2rem;"></i>
                                        Belum ada data pemeriksaan. Klik "Tambah Pemeriksaan" untuk memulai.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php $no = 1;
                            foreach ($semua as $r): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><strong><?= e(jpNomor($r)) ?></strong></td>
                                    <td><?= nl2br(e(wordwrap($r['nama_perusahaan'], 40, "\n", false))) ?></td>
                                    <td><?= e($r['nama_kategori']) ?></td>
                                    <td><?= e($r['nama_objek']) ?></td>
                                    <td><?= e($r['jenis_pemeriksaan']) ?></td>
                                    <td><?= e($r['nama_alat'] ?: '-') ?></td>
                                    <td><?= e(jpLokasi($r)) ?></td>
                                    <td><?= jpTgl($r['tanggal_pemeriksaan']) ?></td>
                                    <?= jpSelAlat($r) ?>
                                    <td><?= jpBadgeStatus($r['status']) ?></td>
                                    <td class="col-aksi" style="text-align:center;">
                                        <div class="table-actions">
                                            <?php if ($r['status'] === 'belum'): ?>
                                                <form method="POST" action="upload.php" class="d-inline">
                                                    <input type="hidden" name="aksi" value="mulai_laporan">
                                                    <input type="hidden" name="pemeriksaan_id" value="<?= (int) $r['id'] ?>">
                                                    <button type="submit" class="btn-primary-custom"
                                                        style="height:28px; padding:0 10px; font-size:0.75rem;"
                                                        data-arp-loading="Membuka form laporan...">
                                                        <i class="bi bi-file-earmark-plus"></i> Buat Laporan
                                                    </button>
                                                </form>
                                                <form method="POST" action="upload.php" class="d-inline"
                                                    data-confirm="Hapus pemeriksaan ini?">
                                                    <input type="hidden" name="aksi" value="hapus_pemeriksaan">
                                                    <input type="hidden" name="pemeriksaan_id" value="<?= (int) $r['id'] ?>">
                                                    <button type="submit" class="btn-danger-custom"
                                                        style="height:28px; padding:0 8px; font-size:0.75rem;"
                                                        data-arp-loading="Menghapus...">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </button>
                                                </form>
                                            <?php elseif ($r['status'] === 'diproses'): ?>
                                                <span class="text-secondary" style="font-size:0.78rem;">
                                                    <i class="bi bi-hourglass-split"></i> Sedang Diproses
                                                </span>
                                            <?php else: ?>
                                                <span class="text-success" style="font-size:0.78rem;">
                                                    <i class="bi bi-check2-circle"></i> Selesai
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelPemeriksaan"></div>
            </div>
        </div>

        <!-- ============================== TAB 2: DIPROSES ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelProses" <?= $active_tab === 'tabPanelProses' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Laporan Sedang Diproses</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari..." data-table-search="tabelProses"
                                onkeyup="handleTableSearch('tabelProses')">
                        </div>
                    </div>
                </div>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelProses" data-freeze-cols="3">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No Laporan</th>
                                <th>Nama Perusahaan</th>
                                <th>Bidang</th>
                                <th>Unit</th>
                                <th>Jenis Pemeriksaan</th>
                                <th>Nama Alat</th>
                                <th>Lokasi</th>
                                <th>Tanggal Pemeriksaan</th>
                                <th>No Unit</th>
                                <th>No Seri</th>
                                <th>Kapasitas</th>
                                <th>Merk</th>
                                <th>Tipe</th>
                                <th>Tahun Alat</th>
                                <th>Tempat Pembuatan Alat</th>
                                <th class="col-aksi" style="text-align:center;">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listProses)): ?>
                                <tr>
                                    <td colspan="17" class="text-center py-4 text-muted">
                                        <i class="bi bi-hourglass d-block mb-2" style="font-size:2rem;"></i>
                                        Tidak ada laporan yang sedang diproses.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php $no = 1;
                            foreach ($listProses as $r): ?>
                                <?php $adaLaporan = !empty($r['laporan_id']); ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td>
                                        <?php if ($adaLaporan): ?>
                                            <span class="badge-success"><?= e(jpNomor($r)) ?></span>
                                        <?php else: ?>
                                            <strong><?= e(jpNomor($r)) ?></strong><br>
                                            <small class="text-secondary" style="font-size:0.7rem;">Belum disimpan</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= nl2br(e(wordwrap($r['nama_perusahaan'], 40, "\n", false))) ?></td>
                                    <td><?= e($r['nama_kategori']) ?></td>
                                    <td><?= e($r['nama_objek']) ?></td>
                                    <td><?= e($r['jenis_pemeriksaan']) ?></td>
                                    <td><?= e($r['nama_alat'] ?: '-') ?></td>
                                    <td><?= e(jpLokasi($r)) ?></td>
                                    <td><?= jpTgl($r['tanggal_pemeriksaan']) ?></td>
                                    <?= jpSelAlat($r) ?>
                                    <td class="col-aksi" style="text-align:center;">
                                        <div class="table-actions">
                                            <form method="POST" action="upload.php" class="d-inline">
                                                <input type="hidden" name="aksi" value="mulai_laporan">
                                                <input type="hidden" name="pemeriksaan_id" value="<?= (int) $r['id'] ?>">
                                                <button type="submit" class="btn btn-outline-primary btn-sm py-1"
                                                    style="font-size:0.75rem;" data-arp-loading="Membuka form laporan...">
                                                    <i class="bi bi-pencil-square"></i> Lanjutkan
                                                </button>
                                            </form>

                                            <?php if ($adaLaporan): ?>
                                                <form method="POST" action="upload.php" class="d-inline"
                                                    data-confirm="Tandai pemeriksaan ini selesai?">
                                                    <input type="hidden" name="aksi" value="selesai_pemeriksaan">
                                                    <input type="hidden" name="pemeriksaan_id" value="<?= (int) $r['id'] ?>">
                                                    <button type="submit" class="btn-primary-custom"
                                                        style="height:28px; padding:0 10px; font-size:0.75rem;"
                                                        data-arp-loading="Menyelesaikan...">
                                                        <i class="bi bi-check2-circle"></i> Selesai
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button type="button" class="btn-primary-custom" disabled
                                                    style="height:28px; padding:0 10px; font-size:0.75rem; opacity:.5; cursor:not-allowed;"
                                                    title="Simpan laporan di form Buat Laporan terlebih dahulu">
                                                    <i class="bi bi-check2-circle"></i> Selesai
                                                </button>
                                                <form method="POST" action="upload.php" class="d-inline"
                                                    data-confirm="Batalkan proses? Pemeriksaan kembali ke daftar.">
                                                    <input type="hidden" name="aksi" value="batal_proses">
                                                    <input type="hidden" name="pemeriksaan_id" value="<?= (int) $r['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm py-1"
                                                        style="font-size:0.75rem;" data-arp-loading="Membatalkan...">
                                                        <i class="bi bi-x-lg"></i> Batalkan
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
                <div class="pagination-custom" id="pagination-tabelProses"></div>
            </div>
        </div>

        <!-- ============================== TAB 3: SELESAI ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelSelesai" <?= $active_tab === 'tabPanelSelesai' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Laporan Selesai Dibuat</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari nomor laporan, perusahaan..."
                                data-table-search="tabelSelesai" onkeyup="handleTableSearch('tabelSelesai')">
                        </div>
                    </div>
                </div>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelSelesai" data-freeze-cols="3">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nomor Laporan</th>
                                <th>Nama Perusahaan</th>
                                <th>Bidang</th>
                                <th>Unit</th>
                                <th>Jenis Pemeriksaan</th>
                                <th>Nama Alat</th>
                                <th>Lokasi</th>
                                <th>Tanggal Pemeriksaan</th>
                                <th>Beres Laporan</th>
                                <th>No Unit</th>
                                <th>No Seri</th>
                                <th>Kapasitas</th>
                                <th>Merk</th>
                                <th>Tipe</th>
                                <th>Tahun Alat</th>
                                <th>Tempat Pembuatan Alat</th>
                                <th class="col-aksi" style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listSelesai)): ?>
                                <tr>
                                    <td colspan="18" class="text-center py-4 text-muted">
                                        <i class="bi bi-folder-x d-block mb-2" style="font-size:2rem;"></i>
                                        Belum ada laporan yang selesai.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php $no = 1;
                            foreach ($listSelesai as $r): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><strong><?= e(jpNomor($r)) ?></strong></td>
                                    <td><?= nl2br(e(wordwrap($r['nama_perusahaan'], 40, "\n", false))) ?></td>
                                    <td><?= e($r['nama_kategori']) ?></td>
                                    <td><?= e($r['nama_objek']) ?></td>
                                    <td><?= e($r['jenis_pemeriksaan']) ?></td>
                                    <td><?= e($r['nama_alat'] ?: '-') ?></td>
                                    <td><?= e(jpLokasi($r)) ?></td>
                                    <td><?= jpTgl($r['tanggal_pemeriksaan']) ?></td>
                                    <td><?= jpTgl($r['lp_tanggal_buat'] ?? $r['tanggal_selesai']) ?></td>
                                    <?= jpSelAlat($r) ?>
                                    <td class="col-aksi" style="text-align:center;">
                                        <div class="table-actions">
                                            <?php if (!empty($r['file_laporan'])): ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="<?= e(hrefBerkas($r['file_laporan'])) ?>" target="_blank"
                                                    title="Lihat">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php $fid = $r['drive_file_id'] ?? driveFileIdDariUrl($r['file_laporan']); ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="<?= e($fid ? urlUnduhLangsungDrive($fid) : hrefBerkas($r['file_laporan'])) ?>"
                                                    target="_blank" title="Unduh">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <small class="text-secondary text-xs d-block mt-2">
                    Edit dan hapus laporan dilakukan di menu Laporan. Jika laporan dihapus, pemeriksaan kembali ke
                    daftar dengan tombol Buat Laporan.
                </small>
                <div class="pagination-custom" id="pagination-tabelSelesai"></div>
            </div>
        </div>
    </div>
</main>

<!-- ===== MODAL: Tambah Pemeriksaan ===== -->
<div class="arp-modal-overlay" id="modalTambahPemeriksaan" onclick="closeModalOutside(event,'modalTambahPemeriksaan')">
    <<div class="arp-modal-box" style="max-width:780px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Tambah Pemeriksaan</h5>
                <small class="text-muted">Catat pemeriksaan, lalu buat laporannya dari daftar.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalTambahPemeriksaan')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="upload.php">
                <input type="hidden" name="aksi" value="tambah_pemeriksaan">
                <input type="hidden" name="klien_id" id="jp-klien-id">

                <!-- Perusahaan (kiri) | Nama Alat (kanan) -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Nama Perusahaan *</label>
                        <input type="text" name="nama_perusahaan" id="jp-cari-perusahaan" class="form-control-custom"
                            autocomplete="off" placeholder="Ketik nama perusahaan..." required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Nama Alat</label>
                        <input type="text" name="nama_alat" class="form-control-custom"
                            placeholder="Cth: Forklift Unit 2">
                    </div>
                </div>

                <!-- Bidang (kiri) | Unit (kanan) -->
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Bidang Objek *</label>
                        <select name="id_kategori" id="jp-kategori" class="select-custom" required>
                            <option value="">-- Pilih bidang objek --</option>
                            <?php foreach ($daftar_kategori_objek as $k): ?>
                                <option value="<?= (int) $k['id_kategori'] ?>"><?= e($k['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Unit Objek *</label>
                        <select name="id_jenis" id="jp-jenis" class="select-custom" required disabled>
                            <option value="">-- Pilih bidang objek dahulu --</option>
                        </select>
                    </div>
                </div>

                <!-- Selanjutnya memanjang ke bawah -->
                <div class="mt-3" id="jp-nomor-wrap" style="display:none;">
                    <label class="form-label fw-semibold mb-2">Nomor Laporan</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="text" name="no_urut" id="jp-no-urut" class="form-control-custom"
                            style="max-width:110px;" inputmode="numeric" pattern="\d*">
                        <div class="form-control-custom field-readonly text-secondary" id="jp-no-suffix"
                            style="flex:1 1 auto;"></div>
                    </div>
                    <small class="text-secondary text-xs d-block mt-1" id="jp-no-hint">
                        Terisi otomatis sesuai urutan berikutnya; boleh diubah. Kosongkan untuk otomatis.
                    </small>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2">Jenis Pemeriksaan *</label>
                    <select name="jenis_pemeriksaan" class="select-custom" required>
                        <option value="Pemeriksaan Berkala">Pemeriksaan Berkala</option>
                        <option value="Pemeriksaan Baru">Pemeriksaan Baru</option>
                    </select>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2">Tanggal Pemeriksaan *</label>
                    <input type="date" name="tanggal_pemeriksaan" class="form-control-custom"
                        value="<?= date('Y-m-d') ?>" required>
                </div>

                <!-- Data alat: muncul setelah bidang + unit dipilih -->
                <div id="jp-alat-wrap" style="display:none;"></div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalTambahPemeriksaan')">Batal</button>
                    <button type="submit" class="btn-primary-custom" data-arp-loading="Menyimpan...">
                        <i class="bi bi-check-lg"></i> Simpan</button>
                </div>
            </form>
        </div>
</div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelPemeriksaan', 10);
        initTablePagination('tabelProses', 10);
        initTablePagination('tabelSelesai', 10);
    });

    // Bidang -> Unit -> (field alat + nomor laporan)
    (function () {
        var selK = document.getElementById('jp-kategori');
        var selJ = document.getElementById('jp-jenis');
        var alatWrap = document.getElementById('jp-alat-wrap');
        var nomorWrap = document.getElementById('jp-nomor-wrap');
        var urutInp = document.getElementById('jp-no-urut');
        var suffixEl = document.getElementById('jp-no-suffix');
        var hintEl = document.getElementById('jp-no-hint');
        var defaultHint = hintEl.textContent;

        function resetInfo() {
            alatWrap.innerHTML = '';
            alatWrap.style.display = 'none';
            nomorWrap.style.display = 'none';
            urutInp.value = '';
            suffixEl.textContent = '';
        }

        function muatInfo() {
            resetInfo();
            if (!selK.value || !selJ.value) return;
            fetch('upload.php?ajax=info_alat&id_kategori=' + encodeURIComponent(selK.value)
                + '&id_jenis=' + encodeURIComponent(selJ.value))
                .then(function (r) { return r.json(); })
                .then(function (info) {
                    // Nomor laporan
                    nomorWrap.style.display = '';
                    if (info.ada_template) {
                        urutInp.disabled = false;
                        urutInp.value = info.urut;
                        urutInp.placeholder = info.urut;
                        suffixEl.textContent = info.suffix;
                        hintEl.textContent = defaultHint;
                    } else {
                        urutInp.disabled = true;
                        suffixEl.textContent = '';
                        hintEl.textContent = 'Belum ada template untuk unit ini; nomor ditentukan saat laporan dibuat.';
                    }
                    // Field alat: tampilan seperti Data Umum
                    function buatLabel(teks, cls) {
                        var lb = document.createElement('label');
                        lb.className = cls || 'form-label fw-semibold mb-2';
                        lb.textContent = teks;
                        return lb;
                    }
                    function buatInput(name, ph) {
                        var inp = document.createElement('input');
                        inp.type = 'text';
                        inp.name = name;
                        inp.className = 'form-control-custom';
                        inp.placeholder = ph || '';
                        inp.style.cssText = 'width:100%;min-width:0;box-sizing:border-box;';
                        return inp;
                    }
                    info.fields.forEach(function (f) {
                        var box = document.createElement('div');
                        box.className = 'mt-3';

                        var inline = (f.type === 'pair' && !f.row_label);
                        if (!inline) {
                            box.appendChild(buatLabel(f.label));
                        }

                        if (f.type === 'pair') {
                            var row = document.createElement('div');
                            var sempit = window.innerWidth < 576;
                            row.style.cssText = 'display:grid;align-items:center;gap:8px;width:100%;';

                            if (f.row_label) {
                                row.style.gridTemplateColumns = sempit ? '1fr' : 'minmax(0,1fr) minmax(0,1fr)';
                                row.appendChild(buatInput('ap[' + f.key + '][kiri]', f.kiri));
                                row.appendChild(buatInput('ap[' + f.key + '][kanan]', f.kanan));
                            } else {
                                // Label kiri | input | label kanan | input  (Merk [..] Model [..])
                                row.style.gridTemplateColumns = sempit ? '1fr' : 'auto minmax(0,1fr) auto minmax(0,1fr)';
                                var lbKiri = buatLabel(f.label, 'form-label fw-semibold mb-0 text-secondary');
                                var lbKanan = buatLabel(f.kanan, 'form-label fw-semibold mb-0 text-secondary');
                                lbKiri.style.whiteSpace = 'nowrap';
                                lbKanan.style.whiteSpace = 'nowrap';
                                row.appendChild(lbKiri);
                                row.appendChild(buatInput('ap[' + f.key + '][kiri]', f.kiri));
                                row.appendChild(lbKanan);
                                row.appendChild(buatInput('ap[' + f.key + '][kanan]', f.kanan));
                            }
                            box.appendChild(row);
                        } else if (f.type === 'single' && f.multiline) {
                            var ta = document.createElement('textarea');
                            ta.name = 'ad[' + f.key + ']';
                            ta.rows = 2;
                            ta.className = 'form-control-custom';
                            ta.placeholder = f.hint || '';
                            box.appendChild(ta);
                        } else {
                            var nm = f.type === 'legacy' ? 'alat[' + f.key + ']' : 'ad[' + f.key + ']';
                            var inp = buatInput(nm, f.hint || 'Opsional');
                            inp.style.cssText = '';
                            box.appendChild(inp);
                        }
                        alatWrap.appendChild(box);
                    });
                    alatWrap.style.display = info.fields.length ? '' : 'none';
                });
        }

        selK.addEventListener('change', function () {
            var id = this.value;
            resetInfo();
            selJ.innerHTML = '<option value="">Memuat...</option>';
            selJ.disabled = true;
            if (!id) {
                selJ.innerHTML = '<option value="">-- Pilih bidang objek dahulu --</option>';
                return;
            }
            fetch('upload.php?ajax=unit_objek_by_bidang&id_kategori=' + encodeURIComponent(id))
                .then(function (r) { return r.json(); })
                .then(function (daftar) {
                    selJ.innerHTML = '<option value="">-- Pilih unit objek --</option>';
                    daftar.forEach(function (j) {
                        var o = document.createElement('option');
                        o.value = j.id_jenis;
                        o.textContent = j.nama_objek;
                        selJ.appendChild(o);
                    });
                    selJ.disabled = false;
                });
        });
        selJ.addEventListener('change', muatInfo);
    })();

    // Autocomplete perusahaan
    (function () {
        var input = document.getElementById('jp-cari-perusahaan');
        var hid = document.getElementById('jp-klien-id');
        var timer = null, box = null;
        function tutup() { if (box) { box.remove(); box = null; } }
        input.addEventListener('input', function () {
            hid.value = '';
            clearTimeout(timer);
            var q = input.value.trim();
            if (q === '') { tutup(); return; }
            timer = setTimeout(function () {
                fetch('upload.php?ajax=cari_klien&q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (daftar) {
                        tutup();
                        if (!daftar.length) return;
                        box = document.createElement('div');
                        box.style.cssText = 'position:absolute;z-index:3000;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 20px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;font-size:.85rem;';
                        var rect = input.getBoundingClientRect();
                        box.style.left = (rect.left + window.scrollX) + 'px';
                        box.style.top = (rect.bottom + window.scrollY + 4) + 'px';
                        box.style.width = rect.width + 'px';
                        document.body.appendChild(box);
                        daftar.forEach(function (k) {
                            var item = document.createElement('div');
                            item.style.cssText = 'padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;';
                            item.textContent = k.nama_perusahaan;
                            item.addEventListener('mousedown', function (e) {
                                e.preventDefault();
                                input.value = k.nama_perusahaan;
                                hid.value = k.id;
                                tutup();
                            });
                            box.appendChild(item);
                        });
                    });
            }, 250);
        });
        input.addEventListener('blur', function () { setTimeout(tutup, 150); });
    })();

    // Loading untuk form POST yang belum punya data-arp-loading
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (e.defaultPrevented || !f || f.tagName !== 'FORM') return;
        if ((f.method || '').toLowerCase() !== 'post') return;
        if (f.querySelector('[data-arp-loading]')) return;
        if (window.arpShowLoader) window.arpShowLoader('Memproses...');
    });
</script>

<?php include "../includes/footer.php"; ?>