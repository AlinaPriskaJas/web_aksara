<?php
// ahli_k3/pemeriksaan.php — Modul Laporan Pemeriksaan (Manajemen Laporan)

require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

$pdo = $conn;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once "../includes/functions.php";
require_once "../includes/drive_helper.php";
require_once "../includes/laporan_helper.php";
require_once "../includes/pemeriksaan_helper.php";   // BARU

$page_title = "Laporan Pemeriksaan";
$current_user_id = $_SESSION['user_id'];

// ==========================================
// [AJAX] Autocomplete Nama Perusahaan (Data_Klien)
// ==========================================
if (($_GET['ajax'] ?? '') === 'cari_klien_lp') {
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
// [AJAX] Daftar Unit Objek (Jenis_Objek_K3) untuk satu Bidang Objek tertentu
// Dipakai baik di form Upload Template maupun form Buat Laporan.
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
// [AJAX] Daftar Template_Laporan untuk kombinasi Bidang + Unit Objek
// tertentu -- dipakai untuk mengisi dropdown "Template" di tab Buat Laporan.
// ==========================================
if (($_GET['ajax'] ?? '') === 'template_by_unit') {
    header('Content-Type: application/json');
    $idKategori = (int) ($_GET['id_kategori'] ?? 0);
    $idJenis = (int) ($_GET['id_jenis'] ?? 0);
    $hasil = [];
    if ($idKategori > 0 && $idJenis > 0) {
        $stmt = $pdo->prepare("SELECT id, nama, kode_laporan FROM Template_Laporan WHERE id_kategori = ? AND id_jenis = ? AND drive_file_id IS NOT NULL ORDER BY nama ASC");
        $stmt->execute([$idKategori, $idJenis]);
        $hasil = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($hasil);
    exit;
}

$page_title = "Laporan Pemeriksaan";

// Tab aktif
$tabMap = [
    'daftar' => 'tabPanelDaftarLaporan',
    'buat' => 'tabPanelBuatLaporan',
    'template' => 'tabPanelTemplateLaporan',
];
$tabGet = $_GET['tab'] ?? 'daftar';
$active_tab = $tabMap[$tabGet] ?? 'tabPanelDaftarLaporan';

$flash = $_SESSION['flash_lp'] ?? null;
unset($_SESSION['flash_lp']);

function laporanRedirect(string $tab, array $extraQuery = []): void
{
    $query = array_merge(['tab' => $tab], $extraQuery);
    header('Location: pemeriksaan.php?' . http_build_query($query));
    exit;
}

// ==========================================
// [TAB: UPLOAD TEMPLATE LAPORAN] Simpan template baru
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'upload_template_laporan') {
    try {
        $idKategori = (int) ($_POST['id_kategori'] ?? 0);
        $idJenis = (int) ($_POST['id_jenis'] ?? 0);
        $kodeLaporan = strtoupper(trim($_POST['kode_laporan'] ?? ''));
        $namaTemplate = trim($_POST['nama_template'] ?? '');
        $deskripsi = trim($_POST['deskripsi'] ?? '');

        if ($idKategori <= 0 || $idJenis <= 0) {
            throw new RuntimeException("Bidang Objek dan Unit Objek wajib dipilih.");
        }
        if ($kodeLaporan === '' || $namaTemplate === '') {
            throw new RuntimeException("Kode Laporan dan Nama Template wajib diisi.");
        }
        if (!preg_match('/^[A-Z0-9\-]+$/', $kodeLaporan)) {
            throw new RuntimeException("Kode Laporan hanya boleh huruf, angka, dan tanda hubung (-), tanpa spasi.");
        }

        $stmtKategori = $pdo->prepare("SELECT nama_kategori FROM Kategori_Objek_K3 WHERE id_kategori = ?");
        $stmtKategori->execute([$idKategori]);
        $namaKategori = $stmtKategori->fetchColumn();

        $stmtJenis = $pdo->prepare("SELECT nama_objek FROM Jenis_Objek_K3 WHERE id_jenis = ? AND id_kategori = ?");
        $stmtJenis->execute([$idJenis, $idKategori]);
        $namaJenis = $stmtJenis->fetchColumn();

        if (!$namaKategori || !$namaJenis) {
            throw new RuntimeException("Bidang Objek / Unit Objek tidak ditemukan atau tidak cocok satu sama lain.");
        }

        if (!isset($_FILES['file_template']) || $_FILES['file_template']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Silakan lampirkan file template (.docx atau .pdf).");
        }
        $ext = strtolower(pathinfo($_FILES['file_template']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx', 'pdf'], true)) {
            throw new RuntimeException("Format file tidak didukung. Hanya .docx atau .pdf.");
        }
        $format = $ext === 'docx' ? 'word_pdf' : 'pdf_only';

        $hasilScan = ['fields' => [], 'table_fields' => []];
        $labelChecklistDocx = [];
        $labelHasilDocx = [];
        $labelVisualDocx = [];
        $labelDimensiDocx = [];
        $labelKetelDocx = [];
        $labelCkDocx = [];
        $labelUkurDocx = [];
        $labelSfdDocx = [];
        $labelVfDocx = [];
        $labelDcpDocx = [];
        $labelTabelUjiDocx = [];
        $labelPvfDocx = []; // <<< BARU

        if ($format === 'word_pdf') {
            $hasilScan = lp_scan_placeholder_docx($_FILES['file_template']['tmp_name']);
            $labelChecklistDocx = lp_scan_label_checklist_docx($_FILES['file_template']['tmp_name']);
            $labelHasilDocx = lp_scan_label_hasil_docx($_FILES['file_template']['tmp_name']);
            $labelVisualDocx = lp_scan_label_visual_docx($_FILES['file_template']['tmp_name']);
            $labelDimensiDocx = lp_scan_label_dimensi_docx($_FILES['file_template']['tmp_name']);
            $labelKetelDocx = lp_scan_label_ketel_docx($_FILES['file_template']['tmp_name']);
            $labelCkDocx = lp_scan_label_ck_docx($_FILES['file_template']['tmp_name']);
            $labelUkurDocx = lp_scan_label_ukur_docx($_FILES['file_template']['tmp_name']);
            $labelSfdDocx = lp_scan_label_sfd_docx($_FILES['file_template']['tmp_name']);
            $labelVfDocx = lp_scan_label_vf_docx($_FILES['file_template']['tmp_name']);
            $labelDcpDocx = lp_scan_label_dcp_docx($_FILES['file_template']['tmp_name']);
            $labelTabelUjiDocx = lp_scan_label_tabel_uji_semua($_FILES['file_template']['tmp_name']);
            $labelPvfDocx = lp_scan_label_pvf_docx($_FILES['file_template']['tmp_name']);   // <<< BARU
        }

        $fieldsJson = json_encode(
            lp_build_fields_dengan_label(
                $hasilScan,
                [],
                $labelChecklistDocx,
                $labelHasilDocx,
                $labelVisualDocx,
                $labelDimensiDocx,
                $labelKetelDocx,
                $labelCkDocx,
                $labelUkurDocx,
                $labelSfdDocx,
                $labelVfDocx,
                $labelDcpDocx,
                $labelTabelUjiDocx,
                $labelPvfDocx   // <<< BARU
            ),
            JSON_UNESCAPED_UNICODE
        );

        $namaFileDrive = lp_slugify_nama_template($namaTemplate) . '.' . $ext;
        $hasilDrive = arp_upload_ke_drive(
            $_FILES['file_template']['tmp_name'],
            $namaFileDrive,
            $ext === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf',
            0,
            lp_folder_template($namaKategori, $namaJenis)
        );
        if (!$hasilDrive || empty($hasilDrive['link'])) {
            throw new RuntimeException("Gagal mengunggah template ke Google Drive: " . arp_drive_last_error());
        }

        $stmt = $pdo->prepare("INSERT INTO Template_Laporan
            (nama, deskripsi, kode_laporan, id_kategori, id_jenis, drive_file_id, drive_link, format, fields_json, diupload_oleh)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $namaTemplate,
            $deskripsi !== '' ? $deskripsi : null,
            $kodeLaporan,
            $idKategori,
            $idJenis,
            $hasilDrive['file_id'],
            $hasilDrive['link'],
            $format,
            $fieldsJson,
            $current_user_id,
        ]);

        $_SESSION['flash_lp'] = ['type' => 'success', 'msg' => "Template \"{$namaTemplate}\" berhasil diunggah untuk {$namaKategori} > {$namaJenis}."];
    } catch (Throwable $e) {
        $_SESSION['flash_lp'] = ['type' => 'error', 'msg' => 'Gagal menyimpan template: ' . $e->getMessage()];
    }
    laporanRedirect('template');
}

// ==========================================
// [TAB: UPLOAD TEMPLATE LAPORAN] Hapus template
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus_template_laporan') {
    try {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM Template_Laporan WHERE id = ?");
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch();
        if (!$tpl) {
            throw new RuntimeException("Template tidak ditemukan.");
        }

        $cekDipakai = $pdo->prepare("SELECT COUNT(*) FROM Laporan_Pemeriksaan WHERE template_id = ?");
        $cekDipakai->execute([$templateId]);
        if ((int) $cekDipakai->fetchColumn() > 0) {
            throw new RuntimeException("Template ini sudah pernah dipakai membuat laporan, tidak bisa dihapus.");
        }

        $pdo->prepare("DELETE FROM Template_Laporan WHERE id = ?")->execute([$templateId]);
        if (!empty($tpl['drive_file_id'])) {
            arp_hapus_file_drive($tpl['drive_file_id']);
        }
        $_SESSION['flash_lp'] = ['type' => 'success', 'msg' => 'Template "' . $tpl['nama'] . '" berhasil dihapus.'];
    } catch (Throwable $e) {
        $_SESSION['flash_lp'] = ['type' => 'error', 'msg' => 'Gagal menghapus template: ' . $e->getMessage()];
    }
    laporanRedirect('template');
}

// ==========================================
// [TAB: UPLOAD TEMPLATE LAPORAN] Edit data template
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'edit_template_laporan') {
    try {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $idKategori = (int) ($_POST['id_kategori'] ?? 0);
        $idJenis = (int) ($_POST['id_jenis'] ?? 0);
        $kodeLaporan = strtoupper(trim($_POST['kode_laporan'] ?? ''));
        $namaTemplate = trim($_POST['nama_template'] ?? '');
        $deskripsi = trim($_POST['deskripsi'] ?? '');

        $stmt = $pdo->prepare("SELECT id FROM Template_Laporan WHERE id = ?");
        $stmt->execute([$templateId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException("Template tidak ditemukan.");
        }
        if ($idKategori <= 0 || $idJenis <= 0) {
            throw new RuntimeException("Bidang Objek dan Unit Objek wajib dipilih.");
        }
        if ($kodeLaporan === '' || $namaTemplate === '') {
            throw new RuntimeException("Kode Laporan dan Nama Template wajib diisi.");
        }
        if (!preg_match('/^[A-Z0-9\-]+$/', $kodeLaporan)) {
            throw new RuntimeException("Kode Laporan hanya boleh huruf, angka, dan tanda hubung (-), tanpa spasi.");
        }

        // Unit harus benar-benar milik bidang yang dipilih
        $cek = $pdo->prepare("SELECT COUNT(*) FROM Jenis_Objek_K3 WHERE id_jenis = ? AND id_kategori = ?");
        $cek->execute([$idJenis, $idKategori]);
        if ((int) $cek->fetchColumn() === 0) {
            throw new RuntimeException("Unit Objek tidak cocok dengan Bidang Objek yang dipilih.");
        }

        $pdo->prepare("UPDATE Template_Laporan
                       SET nama = ?, deskripsi = ?, kode_laporan = ?, id_kategori = ?, id_jenis = ?
                       WHERE id = ?")
            ->execute([
                $namaTemplate,
                $deskripsi !== '' ? $deskripsi : null,
                $kodeLaporan,
                $idKategori,
                $idJenis,
                $templateId,
            ]);

        $_SESSION['flash_lp'] = ['type' => 'success', 'msg' => "Template \"{$namaTemplate}\" berhasil diperbarui."];
    } catch (Throwable $e) {
        $_SESSION['flash_lp'] = ['type' => 'error', 'msg' => 'Gagal memperbarui template: ' . $e->getMessage()];
    }
    laporanRedirect('template');
}

// ==========================================
// [TAB: UPLOAD TEMPLATE LAPORAN] Sinkronkan field dari file Word terbaru
// (dipanggil setelah admin edit template langsung di Google Docs/Drive)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'sinkron_template_laporan') {
    $tabTujuan = ($_POST['redirect_tab'] ?? '') === 'buat' ? 'buat' : 'template';
    $extraQuery = [];
    if ($tabTujuan === 'buat') {
        $extraQuery = [
            'id_kategori' => (int) ($_POST['id_kategori'] ?? 0),
            'id_jenis' => (int) ($_POST['id_jenis'] ?? 0),
            'template_id' => (int) ($_POST['template_id'] ?? 0),
        ];
    }

    try {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM Template_Laporan WHERE id = ?");
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch();
        if (!$tpl) {
            throw new RuntimeException("Template tidak ditemukan.");
        }

        lp_scan_ulang_template($pdo, $tpl);

        $_SESSION['flash_lp'] = [
            'type' => 'success',
            'msg' => "Template \"{$tpl['nama']}\" berhasil disinkronkan. Perubahan dari file Word sudah terbaca.",
        ];
    } catch (Throwable $e) {
        $_SESSION['flash_lp'] = ['type' => 'error', 'msg' => 'Gagal menyinkronkan template: ' . $e->getMessage()];
    }

    laporanRedirect($tabTujuan, $extraQuery);
}

// ==========================================
// [TAB: DAFTAR LAPORAN] Upload laporan MANUAL (tanpa template, sama seperti
// alur lama di ahlik3/pemeriksaan.php, tapi dilakukan dari sisi ahli_k3).
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'upload_laporan_manual') {
    try {
        $nomorLaporan = trim($_POST['nomor_laporan'] ?? '');
        $namaPerusahaan = trim($_POST['nama_perusahaan'] ?? '');
        $idKategori = (int) ($_POST['id_kategori'] ?? 0);
        $idJenis = (int) ($_POST['id_jenis'] ?? 0);
        $unitObjekSpesifik = trim($_POST['unit_objek_spesifik'] ?? '');
        $jenisPemeriksaan = in_array($_POST['jenis_pemeriksaan'] ?? '', ['Pemeriksaan Baru', 'Pemeriksaan Berkala'], true)
            ? $_POST['jenis_pemeriksaan'] : 'Pemeriksaan Berkala';
        $tanggalBuat = $_POST['tanggal_buat'] ?? date('Y-m-d');

        if ($nomorLaporan === '' || $namaPerusahaan === '' || $idKategori <= 0 || $idJenis <= 0) {
            throw new RuntimeException("Nomor Laporan, Nama Perusahaan, Bidang Objek, dan Unit Objek wajib diisi.");
        }

        $stmtKategori = $pdo->prepare("SELECT nama_kategori FROM Kategori_Objek_K3 WHERE id_kategori = ?");
        $stmtKategori->execute([$idKategori]);
        $namaKategori = $stmtKategori->fetchColumn();

        $stmtJenis = $pdo->prepare("SELECT nama_objek FROM Jenis_Objek_K3 WHERE id_jenis = ? AND id_kategori = ?");
        $stmtJenis->execute([$idJenis, $idKategori]);
        $namaJenis = $stmtJenis->fetchColumn();

        if (!$namaKategori || !$namaJenis) {
            throw new RuntimeException("Bidang Objek / Unit Objek tidak ditemukan atau tidak cocok satu sama lain.");
        }

        $unitObjekTampil = $unitObjekSpesifik !== '' ? $unitObjekSpesifik : $namaJenis;
        if (!isset($_FILES['file_laporan']) || $_FILES['file_laporan']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Silakan lampirkan file laporan.");
        }
        $ext = strtolower(pathinfo($_FILES['file_laporan']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'], true)) {
            throw new RuntimeException("Format file tidak didukung.");
        }

        $mimeMap = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
        ];
        $namaFileDrive = lp_nama_file_manual($namaPerusahaan, $ext);
        $hasilDrive = arp_upload_ke_drive(
            $_FILES['file_laporan']['tmp_name'],
            $namaFileDrive,
            $mimeMap[$ext],
            0,
            lp_folder_hasil($namaKategori, $namaJenis)
        );
        if (!$hasilDrive || empty($hasilDrive['link'])) {
            throw new RuntimeException("Gagal mengunggah file ke Google Drive: " . arp_drive_last_error());
        }

        $stmt = $pdo->prepare("INSERT INTO Laporan_Pemeriksaan
            (klien_id, objek_id, nomor_laporan, jenis_pemeriksaan, tanggal_buat, tanggal_pemeriksaan, ahli_k3_id, template_id, dibuat_oleh, file_laporan, drive_file_id, drive_link, isi_data)
            VALUES (NULL, NULL, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $nomorLaporan,
            $jenisPemeriksaan,
            $tanggalBuat,
            $tanggalBuat,
            $current_user_id,
            $hasilDrive['link'],
            $hasilDrive['file_id'],
            $hasilDrive['link'],
            json_encode([
                'sumber' => 'upload_manual',
                'nama_perusahaan' => $namaPerusahaan,
                'nama_kategori' => $namaKategori,
                'unit_objek' => $unitObjekTampil,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $_SESSION['flash_lp'] = ['type' => 'success', 'msg' => "Laporan {$nomorLaporan} berhasil diunggah."];
    } catch (Throwable $e) {
        $_SESSION['flash_lp'] = ['type' => 'error', 'msg' => 'Gagal mengunggah laporan: ' . $e->getMessage()];
    }
    laporanRedirect('daftar');
}

// ==========================================
// [TAB: BUAT LAPORAN] Generate laporan dari Template_Laporan
// ==========================================
$errorGenerateLaporan = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'generate_laporan') {
    $active_tab = 'tabPanelBuatLaporan';
    $isPreviewOnly = ($_POST['preview_only'] ?? '') === '1';

    if (!$isPreviewOnly) {
        try {
            $editIdPost = (int) ($_POST['edit_id'] ?? 0);
            $laporanLama = null;
            if ($editIdPost > 0) {
                $stL = $pdo->prepare("SELECT * FROM Laporan_Pemeriksaan WHERE id = ?");
                $stL->execute([$editIdPost]);
                $laporanLama = $stL->fetch();
                if (!$laporanLama) {
                    throw new RuntimeException("Laporan yang akan diedit tidak ditemukan.");
                }
            }
            $templateId = $laporanLama ? (int) $laporanLama['template_id'] : (int) ($_POST['template_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT tl.*, k.nama_kategori, j.nama_objek
                                    FROM Template_Laporan tl
                                    JOIN Kategori_Objek_K3 k ON k.id_kategori = tl.id_kategori
                                    JOIN Jenis_Objek_K3 j ON j.id_jenis = tl.id_jenis
                                    WHERE tl.id = ?");
            $stmt->execute([$templateId]);
            $tpl = $stmt->fetch();

            if (!$tpl || empty($tpl['drive_file_id'])) {
                throw new RuntimeException("Template tidak ditemukan / belum terhubung ke Google Drive.");
            }
            if ($tpl['format'] !== 'word_pdf') {
                throw new RuntimeException("Template ini bukan file Word (.docx), tidak bisa digenerate otomatis.");
            }

            $namaPerusahaan = trim($_POST['dinamis']['nama_perusahaan'] ?? ($_POST['nama_perusahaan'] ?? ''));
            $klienId = (int) ($_POST['klien_id'] ?? 0);
            $unitObjekInput = trim($_POST['unit_objek_input'] ?? '') ?: $tpl['nama_objek'];
            $noUrutManual = trim($_POST['no_urut_manual'] ?? '');

            // Mode edit: nomor laporan TIDAK berubah
            $nomorLaporan = $laporanLama
                ? $laporanLama['nomor_laporan']
                : lp_generate_nomor($pdo, $tpl['kode_laporan'], $noUrutManual);

            $dataFormMentah = [];
            $dataFormDocx = [];
            foreach ($_POST['dinamis'] ?? [] as $fieldName => $fieldValue) {
                $fieldValue = trim((string) $fieldValue);
                $dataFormMentah[$fieldName] = $fieldValue;
                if (preg_match('/tanggal|tgl/i', $fieldName) && $fieldValue !== '') {
                    $fieldValue = lp_format_tanggal_indonesia($fieldValue);
                }
                $dataFormDocx[$fieldName] = $fieldValue;
            }

            $items = [];
            $itemsMentah = [];
            foreach ($_POST['items'] ?? [] as $baris) {
                $barisMentah = array_map('trim', (array) $baris);
                $adaIsi = false;
                foreach ($barisMentah as $v) {
                    if ($v !== '') {
                        $adaIsi = true;
                        break;
                    }
                }
                if (!$adaIsi)
                    continue;
                $items[] = $barisMentah;
                $itemsMentah[] = $barisMentah;
            }

            // >>> PINDAHKAN KE SINI, SEBELUM lp_dengan_template_sementara() <
            $hasilFieldsUntukGenerate = lp_muat_fields_template($pdo, $tpl);
            $checklistDef = $hasilFieldsUntukGenerate['checklist'] ?? [];

            $inputChecklistMentah = $_POST['checklist'] ?? [];
            $inputKeteranganMentah = $_POST['keterangan'] ?? [];

            $fieldChecklistTerisi = lp_expand_checklist_ke_field(
                $checklistDef,
                $inputChecklistMentah,
                $inputKeteranganMentah
            );

            $dataFormDocx = array_merge($dataFormDocx, $fieldChecklistTerisi);

            // >>> Isi otomatis tabel "Pemeriksaan Visual"
            $inputVisualStatus = $_POST['visual_status'] ?? [];
            $inputVisualKet = $_POST['visual_ket'] ?? [];
            $dataFormDocx = array_merge(
                $dataFormDocx,
                lp_expand_visual_ke_field($hasilFieldsUntukGenerate['visual'] ?? [], $inputVisualStatus, $inputVisualKet)
            );

            // >>> Isi otomatis tabel "Pemeriksaan Dimensi"
            $inputDimensiNilai = $_POST['dimensi_nilai'] ?? [];
            $inputDimensiKet = $_POST['dimensi_ket'] ?? [];
            $dataFormDocx = array_merge(
                $dataFormDocx,
                lp_expand_dimensi_ke_field($hasilFieldsUntukGenerate['dimensi'] ?? [], $inputDimensiNilai, $inputDimensiKet)
            );

            // >>> Isi otomatis field BERPASANGAN (Model/Type, No.Serie/No.Unit, Tempat/Tahun)
            $inputPasangan = $_POST['pasangan'] ?? [];
            $dataFormDocx = array_merge(
                $dataFormDocx,
                lp_expand_pasangan_ke_field($hasilFieldsUntukGenerate['fields'] ?? [], $inputPasangan)
            );

            // >>> Isi otomatis blok "C. Pengukuran Tegangan"
            $inputTeganganStatus = $_POST['tegangan_status'] ?? [];
            $dataFormDocx = array_merge(
                $dataFormDocx,
                lp_expand_tegangan_ke_field($hasilFieldsUntukGenerate['tegangan'] ?? [], $inputTeganganStatus)
            );

            // >>> Turunan Motor Diesel: Negara & Tahun dari lokasi_tahun_pembuatan
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_turunan_motor_diesel($inputPasangan));

            // Penanggung jawab kosong -> samakan dengan Perusahaan Pemakai
            if (
                array_key_exists('penanggung_jawab', $dataFormDocx)
                && trim((string) $dataFormDocx['penanggung_jawab']) === ''
            ) {
                $dataFormDocx['penanggung_jawab'] = $namaPerusahaan;
            }

            // Kapasitas "705" -> "705 kW"
            $dataFormDocx = lp_terapkan_satuan_otomatis($dataFormDocx);

            // Kapasitas "705" -> "705 kW"
            $dataFormDocx = lp_terapkan_satuan_otomatis($dataFormDocx);

            // >>> Data Teknik: satuan otomatis (angka & rentang) + kosong -> "-"
            $dataFormDocx = lp_terapkan_satuan_dtk($dataFormDocx);

            // >>> II. Data Teknis (Dump Truck/Kendaraan Angkut): satuan otomatis + kosong -> "-"
            $dataFormDocx = lp_terapkan_satuan_dtr($dataFormDocx);

            // >>> D. Pengukuran Tegangan (BARU)
            $dataFormDocx = lp_terapkan_vtg_kosong($dataFormDocx);

            foreach (lp_hasil_ukur_semua_field() as $fHsl) {
                if (array_key_exists($fHsl, $dataFormDocx) && trim((string) $dataFormDocx[$fHsl]) === '') {
                    $dataFormDocx[$fHsl] = '-';
                }
            }

            foreach (LP_FIELD_STRIP_JIKA_KOSONG as $fStrip) {
                if (array_key_exists($fStrip, $dataFormDocx) && trim((string) $dataFormDocx[$fStrip]) === '') {
                    $dataFormDocx[$fStrip] = '-';
                }
            }

            foreach (lp_data_teknis_semua_field() as $fDt) {
                if (array_key_exists($fDt, $dataFormDocx) && trim((string) $dataFormDocx[$fDt]) === '') {
                    $dataFormDocx[$fDt] = '-';
                }
            }

            foreach (lp_chk_bejana_semua_field() as $fChk) {
                if (array_key_exists($fChk, $dataFormDocx)) {
                    $dataFormDocx[$fChk] = lp_format_chk_bejana($fChk, (string) $dataFormDocx[$fChk]);
                }
            }

            // >>> Isi otomatis field "_hari" (mis. tanggal_pemeriksaan_hari)
            // dari nilai field tanggal induknya, berupa gabungan "Hari, Tanggal"
            // (mis. "Senin, 17 September 2026"). Field ini sengaja tidak
            // ditampilkan sebagai input terpisah di form.
            foreach (($hasilFieldsUntukGenerate['field_hari'] ?? []) as $fieldHari => $fieldTanggalInduk) {
                $nilaiTanggalMentah = $dataFormMentah[$fieldTanggalInduk] ?? '';
                $dataFormDocx[$fieldHari] = $nilaiTanggalMentah !== ''
                    ? lp_hari_tanggal_indonesia($nilaiTanggalMentah)
                    : '';
            }
            // <<< SAMPAI SINI DIPINDAH KE ATAS

            // >>> Isi otomatis blok "Pengujian yang digunakan"
            $inputPengujianMentah = $_POST['pengujian'] ?? [];
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_pengujian($inputPengujianMentah));

            // >>> Isi otomatis blok "Perhitungan Arus Nominal (In)"
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_arus_nominal($dataFormMentah));

            // >>> Isi otomatis blok "Perhitungan Pembatas Arus / Rating Proteksi Utama"
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_proteksi_utama($dataFormMentah));

            // >>> Isi otomatis blok "Jenis dan Ukuran Kabel yang Digunakan"           // <<< TAMBAHAN
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_jenis_kabel($dataFormMentah));

            // >>> Isi otomatis blok "Perhitungan Keseimbangan Beban RST"
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_keseimbangan_rst($dataFormMentah));  // <<< TAMBAHAN

            // >>> Isi otomatis blok "Perhitungan Thickness Test"
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_thickness($dataFormMentah));

            // >>> Isi otomatis blok "Analisis - Perhitungan Pondasi"
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_pondasi($dataFormMentah));

            // >>> Isi otomatis blok "Analisis - Analisa Komponen (Hidrolik)"
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_komponen_hidrolik($dataFormMentah));

            // >>> Isi otomatis blok "ANALISIS - A. Analisa Komponen" (Dump Truck) — BEDA dari kmp_
            $dataFormDocx = array_merge($dataFormDocx, lp_hitung_analisa_komponen($dataFormMentah));

            // >>> Isi otomatis tabel "Pemeriksaan Visual" KETEL UAP
            $inputKetelStatus = $_POST['ketel_status'] ?? [];
            $inputKetelKet = $_POST['ketel_ket'] ?? [];
            $dataFormDocx = array_merge(
                $dataFormDocx,
                lp_expand_ketel_visual_ke_field($hasilFieldsUntukGenerate['ketel_visual'] ?? [], $inputKetelStatus, $inputKetelKet)
            );

            // >>> Isi otomatis tabel "Pemeriksaan Visual & Fungsi"
            $inputVfStatus = $_POST['vf_status'] ?? [];
            $inputVfKet = $_POST['vf_ket'] ?? [];
            $dataFormDocx = array_merge(
                $dataFormDocx,
                lp_expand_vf_ke_field($hasilFieldsUntukGenerate['vf'] ?? [], $inputVfStatus, $inputVfKet)
            );

            // >>> Isi otomatis tabel "PEMERIKSAAN VISUAL & FUNGSI" (baru)
            $inputPvfStatus = $_POST['pvf_status'] ?? [];
            $inputPvfKet = $_POST['pvf_ket'] ?? [];
            $dataFormDocx = array_merge(
                $dataFormDocx,
                lp_expand_pvf_ke_field($hasilFieldsUntukGenerate['pvf'] ?? [], $inputPvfStatus, $inputPvfKet)
            );

            // >>> Isi otomatis tabel "Data Checklist Pemeriksaan" (Baik/Buruk)
            $inputCkStatus = $_POST['ck_status'] ?? [];
            $inputCkKet = $_POST['ck_ket'] ?? [];
            $dataFormDocx = array_merge(
                $dataFormDocx,
                lp_expand_ck_ke_field($hasilFieldsUntukGenerate['ck'] ?? [], $inputCkStatus, $inputCkKet)
            );

            // >>> Isi otomatis tabel "Data Checklist Pemeriksaan" (dcp)
            $inputDcpStatus = $_POST['dcp_status'] ?? [];
            $inputDcpKet = $_POST['dcp_ket'] ?? [];
            $dataFormDocx = array_merge(
                $dataFormDocx,
                lp_expand_dcp_ke_field($hasilFieldsUntukGenerate['dcp'] ?? [], $inputDcpStatus, $inputDcpKet)
            );

            // >>> Tabel Pengujian/Pengukuran model baru (A/B/C Mesin Packing)
            $dataFormDocx = lp_terapkan_tabel_uji($dataFormDocx, $hasilFieldsUntukGenerate);

            // >>> PEMERIKSAAN TIDAK MERUSAK (NDT)
            $inputNdt = $_POST['ndt'] ?? [];
            $ndtRows = lp_siapkan_baris_ndt($inputNdt, $_FILES['ndt_foto'] ?? null);

            $inputPuj = $_POST['puj'] ?? [];
            $pujRows = lp_siapkan_baris_puj($inputPuj);

            $inputPjn = $_POST['pjn'] ?? [];
            $pjnRows = lp_siapkan_baris_pjn($inputPjn);

            $fileHasilRelatif = lp_dengan_template_sementara(
                $tpl['drive_file_id'],
                function ($pathTemplateLokal) use ($dataFormDocx, $items, $nomorLaporan, $tpl, $namaPerusahaan, $ndtRows, $pujRows, $pjnRows) {
                    return lp_generate_docx($pathTemplateLokal, $dataFormDocx, $items, $nomorLaporan, $tpl['nama'], $namaPerusahaan, $ndtRows, $pujRows, $pjnRows);
                }
            );

            $pathAbsolut = BASE_PATH . '/' . $fileHasilRelatif;
            $hasilDrive = arp_upload_ke_drive(
                $pathAbsolut,
                basename($fileHasilRelatif),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                0,
                lp_folder_hasil($tpl['nama_kategori'], $tpl['nama_objek'])
            );
            if (is_file($pathAbsolut)) {
                @unlink($pathAbsolut);
            }
            if (!$hasilDrive || empty($hasilDrive['link'])) {
                throw new RuntimeException("Gagal mengunggah laporan ke Google Drive: " . arp_drive_last_error());
            }

            $jenisPemeriksaanSimpan = in_array($_POST['jenis_pemeriksaan'] ?? '', ['Pemeriksaan Baru', 'Pemeriksaan Berkala'], true)
                ? $_POST['jenis_pemeriksaan'] : 'Pemeriksaan Berkala';

            $isiDataJson = json_encode(array_merge($dataFormMentah, [
                'nama_perusahaan' => $namaPerusahaan,
                'unit_objek' => $unitObjekInput,
                '__items' => $itemsMentah,
                '__checklist' => $inputChecklistMentah,
                '__keterangan_checklist' => $inputKeteranganMentah,
                '__pengujian' => $inputPengujianMentah,
                '__visual_status' => $inputVisualStatus,
                '__visual_ket' => $inputVisualKet,
                '__dimensi_nilai' => $inputDimensiNilai,
                '__dimensi_ket' => $inputDimensiKet,
                '__pasangan' => $inputPasangan,
                '__ketel_status' => $inputKetelStatus,
                '__ketel_ket' => $inputKetelKet,
                '__ck_status' => $inputCkStatus,
                '__ck_ket' => $inputCkKet,
                '__tegangan_status' => $inputTeganganStatus,
                '__vf_status' => $inputVfStatus,
                '__vf_ket' => $inputVfKet,
                '__dcp_status' => $inputDcpStatus,
                '__dcp_ket' => $inputDcpKet,
                '__pvf_status' => $inputPvfStatus,
                '__pvf_ket' => $inputPvfKet,
                '__ndt' => $inputNdt,
                '__puj' => $inputPuj,
                '__pjn' => $inputPjn,
            ]), JSON_UNESCAPED_UNICODE);

            $pemeriksaanIdPost = (int) ($_POST['pemeriksaan_id'] ?? 0);   // BARU
            $pdo->beginTransaction();

            if ($laporanLama) {
                // ===== MODE EDIT: UPDATE baris yang sama, ID tidak berubah =====
                $upd = $pdo->prepare("UPDATE Laporan_Pemeriksaan
        SET klien_id = ?, jenis_pemeriksaan = ?, file_laporan = ?, drive_file_id = ?, drive_link = ?, isi_data = ?
        WHERE id = ?");
                $upd->execute([
                    $klienId > 0 ? $klienId : null,
                    $jenisPemeriksaanSimpan,
                    $hasilDrive['link'],
                    $hasilDrive['file_id'],
                    $hasilDrive['link'],
                    $isiDataJson,
                    (int) $laporanLama['id'],
                ]);
            } else {
                $insert = $pdo->prepare("INSERT INTO Laporan_Pemeriksaan
        (klien_id, objek_id, nomor_laporan, jenis_pemeriksaan, tanggal_buat, tanggal_pemeriksaan, ahli_k3_id, template_id, dibuat_oleh, file_laporan, drive_file_id, drive_link, isi_data)
        VALUES (?, NULL, ?, ?, CURDATE(), CURDATE(), NULL, ?, ?, ?, ?, ?, ?)");
                $insert->execute([
                    $klienId > 0 ? $klienId : null,
                    $nomorLaporan,
                    $jenisPemeriksaanSimpan,
                    $templateId,
                    $current_user_id,
                    $hasilDrive['link'],
                    $hasilDrive['file_id'],
                    $hasilDrive['link'],
                    $isiDataJson,
                ]);

                $laporanBaruId = (int) $pdo->lastInsertId();
                if ($pemeriksaanIdPost > 0) {
                    $pdo->prepare("UPDATE Proses_Pemeriksaan
                   SET laporan_id = ?, status = 'diproses'
                   WHERE id = ? AND dibuat_oleh = ? AND laporan_id IS NULL")
                        ->execute([$laporanBaruId, $pemeriksaanIdPost, $current_user_id]);
                }
            }

            $pdo->commit();

            // Mode edit: hapus file Drive LAMA setelah DB sukses (supaya tidak ada file yatim)
            // Mode edit: hapus file Drive LAMA setelah DB sukses (supaya tidak ada file yatim).
            // Dilewati jika file_id baru sama dengan yang lama, agar file baru tidak ikut terhapus.
            if (
                $laporanLama
                && !empty($laporanLama['drive_file_id'])
                && $laporanLama['drive_file_id'] !== $hasilDrive['file_id']
            ) {
                try {
                    arp_hapus_file_drive($laporanLama['drive_file_id']);
                } catch (Throwable $e) {
                }
            }

            if (function_exists('catatAudit')) {
                catatAudit(
                    $pdo,
                    'Laporan Pemeriksaan',
                    $laporanLama ? 'Edit Laporan' : 'Buat Laporan',
                    ($laporanLama ? "Mengedit" : "Membuat") . " laporan {$nomorLaporan} dari template \"{$tpl['nama']}\"",
                    null,
                    ['nomor' => $nomorLaporan, 'perusahaan' => $namaPerusahaan]
                );
            }

            $_SESSION['flash_lp'] = [
                'type' => 'success',
                'msg' => $laporanLama
                    ? "Laporan {$nomorLaporan} berhasil diperbarui."
                    : "Laporan berhasil dibuat dengan nomor {$nomorLaporan}.",
            ];
            if ($pemeriksaanIdPost > 0) {
                header('Location: upload.php?tab=proses');
                exit;
            }
            laporanRedirect('daftar');
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $errorGenerateLaporan = 'Gagal membuat laporan: ' . $e->getMessage();
        }
    }
}

// ==========================================
// [TAB: DAFTAR LAPORAN] Hapus laporan
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus_laporan') {
    try {
        $id = (int) ($_POST['laporan_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM Laporan_Pemeriksaan WHERE id = ?");
        $stmt->execute([$id]);
        $lp = $stmt->fetch();
        if (!$lp) {
            throw new RuntimeException("Laporan tidak ditemukan.");
        }
        jp_reset_by_laporan($pdo, (int) $id);   // BARU
        $pdo->prepare("DELETE FROM Laporan_Pemeriksaan WHERE id = ?")->execute([$id]);
        if (!empty($lp['drive_file_id'])) {
            arp_hapus_file_drive($lp['drive_file_id']);
        }
        $_SESSION['flash_lp'] = ['type' => 'success', 'msg' => 'Laporan berhasil dihapus.'];
    } catch (Throwable $e) {
        $_SESSION['flash_lp'] = ['type' => 'error', 'msg' => 'Gagal menghapus laporan: ' . $e->getMessage()];
    }
    laporanRedirect('daftar');
}

if ($errorGenerateLaporan) {
    $flash = ['type' => 'error', 'msg' => $errorGenerateLaporan];
}

// ==========================================
// DATA UNTUK TAB 1: Daftar Laporan
// ==========================================
$daftar_laporan = $pdo->query("
    SELECT lp.*, dk.nama_perusahaan AS klien_nama_perusahaan, tl.nama AS nama_template,
           k.nama_kategori, j.nama_objek, u.nama_lengkap AS pembuat_nama
    FROM Laporan_Pemeriksaan lp
    LEFT JOIN Data_Klien dk ON lp.klien_id = dk.id
    LEFT JOIN Template_Laporan tl ON lp.template_id = tl.id
    LEFT JOIN Kategori_Objek_K3 k ON tl.id_kategori = k.id_kategori
    LEFT JOIN Jenis_Objek_K3 j ON tl.id_jenis = j.id_jenis
    LEFT JOIN Users u ON lp.dibuat_oleh = u.id
    ORDER BY lp.created_at DESC, lp.id DESC
")->fetchAll();

// ==========================================
// [EDIT LAPORAN] Muat data laporan lama ke form (mode edit)
// ==========================================
$editId = (int) ($_POST['edit_id'] ?? $_GET['edit_id'] ?? 0);
$laporanEdit = null;
if ($editId > 0) {
    $stmtE = $pdo->prepare("SELECT * FROM Laporan_Pemeriksaan WHERE id = ?");
    $stmtE->execute([$editId]);
    $laporanEdit = $stmtE->fetch();

    if (!$laporanEdit || empty($laporanEdit['template_id'])) {
        $_SESSION['flash_lp'] = ['type' => 'error', 'msg' => 'Laporan ini tidak bisa diedit (bukan hasil template / tidak ditemukan).'];
        laporanRedirect('daftar');
    }

    $active_tab = 'tabPanelBuatLaporan';

    // Hanya prefill saat pertama dibuka (GET). Saat POST (Update Preview), nilai dari form dipakai.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $stmtT = $pdo->prepare("SELECT id_kategori, id_jenis FROM Template_Laporan WHERE id = ?");
        $stmtT->execute([(int) $laporanEdit['template_id']]);
        $tplE = $stmtT->fetch();
        if (!$tplE) {
            $_SESSION['flash_lp'] = ['type' => 'error', 'msg' => 'Template laporan ini sudah tidak ada.'];
            laporanRedirect('daftar');
        }
        $_GET['id_kategori'] = (int) $tplE['id_kategori'];
        $_GET['id_jenis'] = (int) $tplE['id_jenis'];
        $_GET['template_id'] = (int) $laporanEdit['template_id'];

        $isi = json_decode($laporanEdit['isi_data'] ?? '', true) ?: [];

        // Field biasa (dinamis) = semua key non-"__"
        $dinamisE = [];
        foreach ($isi as $k => $v) {
            if (strpos((string) $k, '__') === 0 || $k === 'unit_objek')
                continue;
            $dinamisE[$k] = $v;
        }
        $_POST['dinamis'] = $dinamisE;

        // Peta key penyimpanan -> nama input form
        $petaInput = [
            '__items' => 'items',
            '__checklist' => 'checklist',
            '__keterangan_checklist' => 'keterangan',
            '__pengujian' => 'pengujian',
            '__visual_status' => 'visual_status',
            '__visual_ket' => 'visual_ket',
            '__dimensi_nilai' => 'dimensi_nilai',
            '__dimensi_ket' => 'dimensi_ket',
            '__pasangan' => 'pasangan',
            '__ketel_status' => 'ketel_status',
            '__ketel_ket' => 'ketel_ket',
            '__ck_status' => 'ck_status',
            '__ck_ket' => 'ck_ket',
            '__tegangan_status' => 'tegangan_status',
            '__vf_status' => 'vf_status',
            '__vf_ket' => 'vf_ket',
            '__dcp_status' => 'dcp_status',
            '__dcp_ket' => 'dcp_ket',
            '__pvf_status' => 'pvf_status',
            '__pvf_ket' => 'pvf_ket',
            '__ndt' => 'ndt',
            '__puj' => 'puj',
            '__pjn' => 'pjn',
        ];
        foreach ($petaInput as $src => $dst) {
            if (isset($isi[$src]) && is_array($isi[$src])) {
                $_POST[$dst] = $isi[$src];
            }
        }

        $_POST['nama_perusahaan'] = $isi['nama_perusahaan'] ?? '';
        $_POST['klien_id'] = (int) ($laporanEdit['klien_id'] ?? 0);
        $_POST['jenis_pemeriksaan'] = $laporanEdit['jenis_pemeriksaan'];
        $_POST['no_urut_manual'] = explode('/', (string) $laporanEdit['nomor_laporan'])[0];
    }
}

$pemeriksaanId = (int) ($_POST['pemeriksaan_id'] ?? $_GET['pemeriksaan_id'] ?? 0);
$jadwalAktif = null;
if ($pemeriksaanId > 0 && !$editId) {
    $stJ = $pdo->prepare("SELECT * FROM Proses_Pemeriksaan WHERE id = ? AND dibuat_oleh = ?");
    $stJ->execute([$pemeriksaanId, $current_user_id]);
    $jadwalAktif = $stJ->fetch();

    if (!$jadwalAktif || $jadwalAktif['status'] !== 'diproses' || !empty($jadwalAktif['laporan_id'])) {
        $_SESSION['flash_jp'] = ['type' => 'error', 'msg' => 'Pemeriksaan tidak valid atau laporannya sudah dibuat.'];
        header('Location: upload.php?tab=proses');
        exit;
    }

    $active_tab = 'tabPanelBuatLaporan';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $_GET['id_kategori'] = (int) $jadwalAktif['id_kategori'];
        $_GET['id_jenis'] = (int) $jadwalAktif['id_jenis'];

        $_POST['pemeriksaan_id'] = $pemeriksaanId;
        $_POST['nama_perusahaan'] = $jadwalAktif['nama_perusahaan'];
        $_POST['klien_id'] = (int) ($jadwalAktif['klien_id'] ?? 0);
        $_POST['jenis_pemeriksaan'] = $jadwalAktif['jenis_pemeriksaan'];

        $_POST['dinamis']['nama_perusahaan'] = $jadwalAktif['nama_perusahaan'];
        $_POST['dinamis']['perusahaan_pemakai'] = $jadwalAktif['nama_perusahaan'];
        $_POST['dinamis']['tanggal_pemeriksaan'] = $jadwalAktif['tanggal_pemeriksaan'];
        if (!empty($jadwalAktif['lokasi'])) {
            $_POST['dinamis']['lokasi_unit'] = $jadwalAktif['lokasi'];
        }
    }
}


// ==========================================
// DATA UNTUK TAB 2 & 3: Bidang Objek + Unit Objek + Template
// ==========================================
$daftar_kategori_objek = $pdo->query("SELECT id_kategori, kode_kategori, nama_kategori FROM Kategori_Objek_K3 ORDER BY nama_kategori ASC")->fetchAll();
$daftar_jenis_objek_semua = $pdo->query("SELECT id_jenis, id_kategori, nama_objek FROM Jenis_Objek_K3 ORDER BY nama_objek ASC")->fetchAll();

$daftar_template_laporan = $pdo->query("
    SELECT tl.*, k.nama_kategori, j.nama_objek
    FROM Template_Laporan tl
    JOIN Kategori_Objek_K3 k ON k.id_kategori = tl.id_kategori
    JOIN Jenis_Objek_K3 j ON j.id_jenis = tl.id_jenis
    ORDER BY tl.created_at DESC
")->fetchAll();

// Selection state Tab 2 (Buat Laporan) -- dipertahankan lewat GET/POST supaya
// "Update Preview" tidak mereset pilihan Bidang/Unit/Template.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'generate_laporan') {
    $idKategoriTerpilih = (int) ($_POST['id_kategori'] ?? 0);
    $idJenisTerpilih = (int) ($_POST['id_jenis'] ?? 0);
    $templateIdTerpilih = (int) ($_POST['template_id'] ?? 0);
} else {
    $idKategoriTerpilih = (int) ($_GET['id_kategori'] ?? 0);
    $idJenisTerpilih = (int) ($_GET['id_jenis'] ?? 0);
    $templateIdTerpilih = (int) ($_GET['template_id'] ?? 0);
}

$templateTerpilih = null;
$fields_dinamis_lp = [];
$fields_tabel_lp = [];
$fields_checklist_lp = [];
$fields_hasil_lp = [];
$fields_arus_nominal_lp = [];               // <<< TAMBAHKAN
$LP_ARUS_INPUT_FIELDS = ['arus_daya_terpasang', 'arus_tegangan', 'arus_cosphi']; // <<< TAMBAHKAN  
$fields_proteksi_nominal_lp = [];
$LP_PROTEKSI_INPUT_FIELDS = ['proteksi_jenis', 'proteksi_kapasitas'];
$fields_rst_lp = [];                                                   // <<< TAMBAHAN
$LP_RST_INPUT_FIELDS = ['rst_arus_r', 'rst_arus_s', 'rst_arus_t'];      // <<< TAMBAHAN
$fields_kabel_lp = [];                                                  // <<< TAMBAHAN
$LP_KABEL_INPUT_FIELDS = ['kabel_jenis', 'kabel_ukuran', 'kabel_kha_satuan']; // <<< TAMBAHAN
$pengujian_aktif_lp = [];   // <<< BARU
$LP_CHK_BEJANA_FIELDS = [];
$adaChkBejana = false;
$fields_visual_lp = [];
$fields_dimensi_lp = []; // <<< BARU
$fields_ketel_lp = [];
$LP_DATA_TEKNIS_FIELDS = lp_data_teknis_semua_field();
$adaDataTeknis = false;
$LP_DT_TABEL_FIELDS = lp_dt_tabel_semua_field();
$adaDataTeknisTabel = false;
$LP_DTR_FIELDS = lp_dtr_semua_field();
$adaDtr = false;
$LP_DTK_FIELDS = lp_dtk_semua_field();
$adaDtk = false;
$LP_HASIL_UKUR_FIELDS = lp_hasil_ukur_semua_field();
$adaHasilUkur = false;
$adaNdt = false;
$adaPuj = false;
$adaPjn = false;
$fields_ck_lp = [];
$fields_ukur_lp = [];   // <<< BARU
$fields_sfd_lp = [];   // <<< BARU
$fields_tegangan_lp = [];   // <<< BARU
$fields_pondasi_lp = [];
$fields_vf_lp = [];
$fields_pvf_lp = [];   // <<< BARU
$fields_dcp_lp = [];
$fields_tabel_uji_lp = [];
$fields_kmp_lp = [];
$fields_kmp_lp = [];
$fields_thk_lp = [];
$adaVtg = false;   // <<< BARU

if ($active_tab === 'tabPanelBuatLaporan' && $templateIdTerpilih) {
    $stmt = $pdo->prepare("SELECT tl.*, k.nama_kategori, j.nama_objek
                            FROM Template_Laporan tl
                            JOIN Kategori_Objek_K3 k ON k.id_kategori = tl.id_kategori
                            JOIN Jenis_Objek_K3 j ON j.id_jenis = tl.id_jenis
                            WHERE tl.id = ?");
    $stmt->execute([$templateIdTerpilih]);
    $templateTerpilih = $stmt->fetch();
    if ($templateTerpilih) {
        $hasilFields = lp_muat_fields_template($pdo, $templateTerpilih);
        $fields_dinamis_lp = $hasilFields['fields'];
        $fields_tabel_lp = lp_urutkan_kolom_tabel($hasilFields['table_fields']);
        $fields_checklist_lp = $hasilFields['checklist'] ?? [];
        $fields_hasil_lp = $hasilFields['hasil'] ?? [];
        $pengujian_aktif_lp = $hasilFields['pengujian_aktif'] ?? [];   // <<< BARU
        $fields_visual_lp = $hasilFields['visual'] ?? [];
        $fields_dimensi_lp = $hasilFields['dimensi'] ?? [];
        $fields_ketel_lp = $hasilFields['ketel_visual'] ?? [];
        $fields_ck_lp = $hasilFields['ck'] ?? [];
        $fields_ukur_lp = $hasilFields['ukur'] ?? [];   // <<< BARU
        $fields_sfd_lp = $hasilFields['sfd'] ?? [];   // <<< BARU
        $fields_tegangan_lp = $hasilFields['tegangan'] ?? [];
        $fields_vf_lp = $hasilFields['vf'] ?? [];
        $fields_dcp_lp = $hasilFields['dcp'] ?? [];
        $fields_pvf_lp = $hasilFields['pvf'] ?? [];     // <<< BARU

        foreach (array_keys(LP_TABEL_UJI_DEF) as $pUji) {
            $fields_tabel_uji_lp[$pUji] = $hasilFields[$pUji] ?? [];
        }

        // <<< TAMBAHKAN: pisahkan 3 field arus, tapi TIDAK dihapus dari $fields_dinamis_lp
        // (biar $nilai_dinamis_lp di bawah tetap ke-isi nilainya)
        $fields_arus_nominal_lp = array_values(array_filter(
            $fields_dinamis_lp,
            fn($f) => in_array($f['field'], $LP_ARUS_INPUT_FIELDS, true)
        ));

        $fields_proteksi_nominal_lp = array_values(array_filter(                   // <<< TAMBAHAN
            $fields_dinamis_lp,
            fn($f) => in_array($f['field'], $LP_PROTEKSI_INPUT_FIELDS, true)
        ));

        $fields_rst_lp = array_values(array_filter(                            // <<< TAMBAHAN
            $fields_dinamis_lp,
            fn($f) => in_array($f['field'], $LP_RST_INPUT_FIELDS, true)
        ));

        $fields_kabel_lp = array_values(array_filter(                       // <<< TAMBAHAN
            $fields_dinamis_lp,
            fn($f) => in_array($f['field'], $LP_KABEL_INPUT_FIELDS, true)
        ));

        $fields_thk_lp = array_values(array_filter(
            $fields_dinamis_lp,
            fn($f) => in_array($f['field'], LP_THK_INPUT_FIELDS, true)
        ));

        $fields_pondasi_lp = array_values(array_filter(
            $fields_dinamis_lp,
            fn($f) => in_array($f['field'], LP_PND_INPUT_FIELDS, true)
        ));

        $fields_kmp_lp = array_values(array_filter(
            $fields_dinamis_lp,
            fn($f) => in_array($f['field'], LP_KMP_INPUT_FIELDS, true)
        ));

        $fields_akm_lp = array_values(array_filter(          // <<< BARU
            $fields_dinamis_lp,
            fn($f) => in_array($f['field'], LP_AKM_INPUT_FIELDS, true)
        ));

        $LP_CHK_BEJANA_FIELDS = lp_chk_bejana_semua_field();
        $adaChkBejana = (bool) array_intersect(
            $LP_CHK_BEJANA_FIELDS,
            array_column($fields_dinamis_lp, 'field')
        );

        $adaDataTeknis = (bool) array_intersect(
            $LP_DATA_TEKNIS_FIELDS,
            array_column($fields_dinamis_lp, 'field')
        );

        $adaDtk = (bool) array_intersect(
            $LP_DTK_FIELDS,
            array_column($fields_dinamis_lp, 'field')
        );

        $adaVtg = (bool) array_intersect(          // <<< BARU
            lp_vtg_semua_field(),
            array_column($fields_dinamis_lp, 'field')
        );

        $adaDataTeknisTabel = (bool) array_intersect(
            $LP_DT_TABEL_FIELDS,
            array_column($fields_dinamis_lp, 'field')
        );

        $adaDtr = (bool) array_intersect(
            $LP_DTR_FIELDS,
            array_column($fields_dinamis_lp, 'field')
        );

        $adaHasilUkur = (bool) array_intersect(
            $LP_HASIL_UKUR_FIELDS,
            array_column($fields_dinamis_lp, 'field')
        );

        $adaNdt = (bool) array_intersect(['ndt_no'], array_column($fields_dinamis_lp, 'field'));

        $adaPuj = (bool) array_intersect(['puj_tinggi_angkat'], array_column($fields_dinamis_lp, 'field'));

        $adaPjn = (bool) array_intersect(['pjn_no'], array_column($fields_dinamis_lp, 'field'));

    }
}

$file_template_lp_hilang = $templateTerpilih && empty($templateTerpilih['drive_file_id']);

$nilai_dinamis_lp = [];
foreach ($fields_dinamis_lp as $f) {
    $isTanggal = lp_is_kolom_tanggal($f['field']);
    $nilai_dinamis_lp[$f['field']] = $_POST['dinamis'][$f['field']]
        ?? ($isTanggal ? date('Y-m-d') : (LP_DEFAULT_FIELD[$f['field']] ?? ''));
}

$nilai_items_lp = $_POST['items'] ?? [];
if (empty($nilai_items_lp) && !empty($fields_tabel_lp)) {
    $barisKosong = [];
    foreach ($fields_tabel_lp as $kolom) {
        $barisKosong[$kolom['field']] = '';
    }
    $nilai_items_lp = [$barisKosong];
}

$nilai_checklist_lp = $_POST['checklist'] ?? [];
$nilai_keterangan_checklist_lp = $_POST['keterangan'] ?? [];

$nilai_pengujian_lp = $_POST['pengujian'] ?? [];

$nilai_visual_status_lp = $_POST['visual_status'] ?? [];
$nilai_visual_ket_lp = $_POST['visual_ket'] ?? [];
$nilai_dimensi_lp = $_POST['dimensi_nilai'] ?? [];        // <<< BARU
$nilai_dimensi_ket_lp = $_POST['dimensi_ket'] ?? [];      // <<< BARU
$nilai_pasangan_lp = $_POST['pasangan'] ?? [];

$nilai_ketel_status_lp = $_POST['ketel_status'] ?? [];
$nilai_ketel_ket_lp = $_POST['ketel_ket'] ?? [];

$nilai_ck_status_lp = $_POST['ck_status'] ?? [];
$nilai_tegangan_status_lp = $_POST['tegangan_status'] ?? [];
$nilai_ck_ket_lp = $_POST['ck_ket'] ?? [];

$nilai_vf_status_lp = $_POST['vf_status'] ?? [];      // dekat $nilai_ck_status_lp
$nilai_vf_ket_lp = $_POST['vf_ket'] ?? [];
$nilai_dcp_status_lp = $_POST['dcp_status'] ?? [];
$nilai_dcp_ket_lp = $_POST['dcp_ket'] ?? [];
$nilai_pvf_status_lp = $_POST['pvf_status'] ?? [];   // <<< BARU
$nilai_pvf_ket_lp = $_POST['pvf_ket'] ?? [];         // <<< BARU

$nilai_hasil_lp = [];
foreach ($fields_hasil_lp as $h) {
    if (!empty($h['sub'])) {
        foreach ($h['sub'] as $s) {
            $nilai_hasil_lp[$s['field']] = $_POST['dinamis'][$s['field']] ?? '';
        }
    } else {
        $nilai_hasil_lp[$h['field_nilai']] = $_POST['dinamis'][$h['field_nilai']] ?? '';
    }
    if ($h['field_ket']) {
        $nilai_hasil_lp[$h['field_ket']] = $_POST['dinamis'][$h['field_ket']] ?? '';
    }
    if ($h['field_rujukan']) {                                              // <<< BARU
        $nilai_hasil_lp[$h['field_rujukan']] = $_POST['dinamis'][$h['field_rujukan']] ?? '';
    }
    if ($h['field_metode']) {                                               // <<< BARU
        $nilai_hasil_lp[$h['field_metode']] = $_POST['dinamis'][$h['field_metode']] ?? '';
    }
}

$nilai_ukur_lp = [];
foreach ($fields_ukur_lp as $u) {
    if (!empty($u['sub'])) {
        foreach ($u['sub'] as $s) {
            $nilai_ukur_lp[$s['field']] = $_POST['dinamis'][$s['field']] ?? '';
        }
    } else {
        $nilai_ukur_lp[$u['field_nilai']] = $_POST['dinamis'][$u['field_nilai']] ?? '';
    }
    if ($u['field_ket']) {
        $nilai_ukur_lp[$u['field_ket']] = $_POST['dinamis'][$u['field_ket']] ?? '';
    }
}

$nilai_sfd_lp = [];
foreach ($fields_sfd_lp as $u) {
    if (!empty($u['sub'])) {
        foreach ($u['sub'] as $s) {
            $nilai_sfd_lp[$s['field']] = $_POST['dinamis'][$s['field']] ?? '';
        }
    } else {
        $nilai_sfd_lp[$u['field_nilai']] = $_POST['dinamis'][$u['field_nilai']] ?? '';
    }
    if ($u['field_ket']) {
        $nilai_sfd_lp[$u['field_ket']] = $_POST['dinamis'][$u['field_ket']] ?? '';
    }
}

$nilai_tabel_uji_lp = [];
foreach (LP_TABEL_UJI_DEF as $pUji => $defUji) {
    foreach (($fields_tabel_uji_lp[$pUji] ?? []) as $it) {
        $nilai_tabel_uji_lp[$it['field_hasil']] = $_POST['dinamis'][$it['field_hasil']] ?? '';
        if (!empty($it['field_kol2'])) {
            $nilai_tabel_uji_lp[$it['field_kol2']] = $_POST['dinamis'][$it['field_kol2']] ?? '';
        }
    }
}

$preview_nomor_laporan = '(otomatis saat disimpan)';
if ($templateTerpilih) {
    $preview_nomor_laporan = lp_preview_nomor($pdo, $templateTerpilih['kode_laporan']);
}

function badgeStatusLaporan(?string $status): string
{
    return 'badge-success';
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
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelDaftarLaporan' ? ' active' : '' ?>"
                data-tab-target="tabPanelDaftarLaporan" data-tab-key="daftar"
                onclick="switchTab('tabPanelDaftarLaporan', this)">
                <i class="bi bi-clipboard-data me-1"></i> Laporan Pemeriksaan
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelBuatLaporan' ? ' active' : '' ?>"
                data-tab-target="tabPanelBuatLaporan" data-tab-key="buat"
                onclick="switchTab('tabPanelBuatLaporan', this)">
                <i class="bi bi-file-earmark-plus me-1"></i> Buat Laporan Pemeriksaan
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelTemplateLaporan' ? ' active' : '' ?>"
                data-tab-target="tabPanelTemplateLaporan" data-tab-key="template"
                onclick="switchTab('tabPanelTemplateLaporan', this)">
                <i class="bi bi-file-earmark-word me-1"></i> Upload Template Laporan
            </button>
        </div>

        <!-- ============================== TAB 1: DAFTAR LAPORAN ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelDaftarLaporan" <?= $active_tab === 'tabPanelDaftarLaporan' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Laporan Pemeriksaan</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari nomor laporan, perusahaan..."
                                data-table-search="tabelDaftarLaporan"
                                onkeyup="handleTableSearch('tabelDaftarLaporan')">
                        </div>
                        <button type="button" class="btn-secondary-custom"
                            onclick="openModal('modalUploadLaporanManual')">
                            <i class="bi bi-file-earmark-arrow-up"></i> Upload Laporan Pemeriksaan
                        </button>
                        <button class="btn-primary-custom"
                            onclick="switchTab('tabPanelBuatLaporan', document.querySelector('[data-tab-target=tabPanelBuatLaporan]'))">
                            <i class="bi bi-file-earmark-plus"></i> Buat Laporan
                        </button>
                    </div>
                </div>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelDaftarLaporan">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nomor Laporan</th>
                                <th>Bidang Objek</th>
                                <th>Unit Objek</th>
                                <th>Perusahaan</th>
                                <th>Dibuat Oleh</th>
                                <th>Tanggal</th>
                                <th class="col-aksi" style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daftar_laporan)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="bi bi-clipboard-x d-block mb-2" style="font-size:2rem;"></i>
                                        Belum ada data laporan pemeriksaan.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php $no = 1; ?>
                            <?php foreach ($daftar_laporan as $lp): ?>
                                <?php
                                $isiDataLp = json_decode($lp['isi_data'] ?? '', true) ?: [];
                                $namaPerusahaanTampil = $lp['klien_nama_perusahaan'] ?? ($isiDataLp['nama_perusahaan'] ?? '-');
                                $unitTampil = $lp['nama_objek'] ?? ($isiDataLp['unit_objek'] ?? '-');
                                $bidangTampil = $lp['nama_kategori'] ?? ($isiDataLp['nama_kategori'] ?? null);
                                ?>
                                <tr>
                                    <td><?= $no++; ?></td>
                                    <td><strong><?= e($lp['nomor_laporan']) ?></strong>
                                        <?php if (!empty($lp['nama_template'])): ?>
                                            <br><small class="text-secondary"><?= e($lp['nama_template']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($bidangTampil ?: '-') ?></td>
                                    <td><?= e($unitTampil ?: '-') ?></td>
                                    <td><?= e($namaPerusahaanTampil) ?></td>
                                    <td><?= e($lp['pembuat_nama'] ?? '-') ?></td>
                                    <td><?= !empty($lp['tanggal_buat']) ? date('d-m-Y', strtotime($lp['tanggal_buat'])) : '-' ?>
                                    </td>
                                    <td class="col-aksi" style="text-align:center;">
                                        <div class="table-actions">
                                            <?php if (!empty($lp['file_laporan'])): ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="<?= e(hrefBerkas($lp['file_laporan'])) ?>" target="_blank"
                                                    title="Lihat berkas">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php $fileIdUnduh = $lp['drive_file_id'] ?? driveFileIdDariUrl($lp['file_laporan']); ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="<?= e($fileIdUnduh ? urlUnduhLangsungDrive($fileIdUnduh) : hrefBerkas($lp['file_laporan'])) ?>"
                                                    title="Unduh" target="_blank">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($lp['template_id']) && ($isiDataLp['sumber'] ?? '') !== 'upload_manual'): ?>
                                                <a class="btn btn-outline-primary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="pemeriksaan.php?tab=buat&edit_id=<?= (int) $lp['id'] ?>"
                                                    title="Edit laporan" data-arp-loading="Memuat data laporan...">
                                                    <i class="bi bi-pencil-square"></i>
                                                </a>
                                            <?php endif; ?>
                                            <form method="POST" action="pemeriksaan.php" class="d-inline"
                                                data-confirm="Hapus laporan ini? Tindakan tidak bisa dibatalkan.">
                                                <input type="hidden" name="aksi" value="hapus_laporan"> <input type="hidden"
                                                    name="laporan_id" value="<?= (int) $lp['id'] ?>">
                                                <button type="submit" class="btn-danger-custom"
                                                    style="height:28px; padding:0 8px; font-size:0.75rem;"
                                                    data-arp-loading="Menghapus...">
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
                <div class="pagination-custom" id="pagination-tabelDaftarLaporan"></div>
            </div>
        </div>

        <!-- ============================== TAB 2: BUAT LAPORAN ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelBuatLaporan" <?= $active_tab === 'tabPanelBuatLaporan' ? '' : 'style="display:none;"' ?>>
            <div class="card-box" style="font-size:0.82rem;">
                <style>
                    /* ============================================================
       LP DESIGN SYSTEM — Buat Laporan Pemeriksaan
       ============================================================ */
                    #tabPanelBuatLaporan {
                        --lp-primary: #4f46e5;
                        --lp-primary-dark: #3730a3;
                        --lp-primary-soft: #eef2ff;
                        --lp-primary-soft-2: #e0e7ff;
                        --lp-border: #e2e8f0;
                        --lp-border-soft: #edf1f7;
                        --lp-text: #334155;
                        --lp-text-muted: #64748b;
                        --lp-bg-card: #ffffff;
                        --lp-bg-alt: #f8fafc;
                        --lp-radius: 10px;
                        --lp-radius-sm: 6px;
                        --lp-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 1px 8px rgba(15, 23, 42, .03);
                        --lp-shadow-hover: 0 4px 16px rgba(79, 70, 229, .10);
                    }

                    /* === Perbaikan agar label & input form selalu sejajar/lurus === */
                    .lp-field-label {
                        width: 220px;
                        flex: 0 0 220px;
                        font-size: 0.78rem;
                        font-weight: 600;
                        color: var(--lp-text);
                        padding-top: 8px;
                        box-sizing: border-box;
                    }

                    .d-flex.gap-2>.lp-field-label+input.form-control-custom,
                    .d-flex.gap-2>.lp-field-label+textarea.form-control-custom,
                    .d-flex.gap-2>.lp-field-label+div,
                    .d-flex.gap-2>.lp-field-label+select.select-custom {
                        flex: 1 1 auto;
                        min-width: 0;
                    }

                    .lp-subsection-body>.d-flex.gap-2 {
                        margin-left: 0;
                    }

                    @media (max-width: 768px) {
                        .lp-field-label {
                            width: 140px;
                            flex: 0 0 140px;
                        }
                    }

                    /* Input & select di dalam form ini sedikit lebih "hidup" */
                    #tabPanelBuatLaporan .form-control-custom,
                    #tabPanelBuatLaporan .select-custom {
                        border: 1px solid var(--lp-border) !important;
                        border-radius: var(--lp-radius-sm) !important;
                        transition: border-color .15s ease, box-shadow .15s ease;
                    }

                    #tabPanelBuatLaporan .form-control-custom:focus,
                    #tabPanelBuatLaporan .select-custom:focus {
                        border-color: var(--lp-primary) !important;
                        box-shadow: 0 0 0 3px rgba(79, 70, 229, .12) !important;
                        outline: none !important;
                    }

                    .lp-textarea-ket {
                        width: 100%;
                        max-width: 260px;
                        min-width: 140px;
                        resize: none;
                        overflow: hidden;
                        white-space: pre-wrap;
                        word-break: break-word;
                        min-height: 34px;
                        line-height: 1.4;
                        display: block;
                    }

                    /* ---------- Judul tiap bagian form (Data Umum, dst) ---------- */
                    .lp-section-title {
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        font-size: 1.02rem;
                        font-weight: 800;
                        text-transform: uppercase;
                        letter-spacing: .04em;
                        color: var(--lp-primary-dark);
                        padding: 12px 16px;
                        margin: 28px 0 16px 0;
                        background: linear-gradient(135deg, var(--lp-primary-soft) 0%, #f5f3ff 100%);
                        border: 1px solid var(--lp-primary-soft-2);
                        border-left: 4px solid var(--lp-primary);
                        border-radius: var(--lp-radius);
                        box-shadow: var(--lp-shadow);
                    }

                    .lp-section-title::before {
                        content: "";
                        width: 8px;
                        height: 8px;
                        border-radius: 50%;
                        background: var(--lp-primary);
                        flex-shrink: 0;
                    }

                    /* pertama kali muncul, jangan terlalu jauh dari elemen di atasnya */
                    #tabPanelBuatLaporan form>.lp-section-title:first-of-type,
                    #tabPanelBuatLaporan .lp-step>.lp-section-title:first-child {
                        margin-top: 4px;
                    }

                    /* ---------- Sub-bagian (Shell/Badan, Tutup Head, dst) ---------- */
                    .lp-subsection {
                        border: 1px solid var(--lp-border);
                        border-radius: var(--lp-radius);
                        margin-bottom: 16px;
                        overflow: hidden;
                        background: var(--lp-bg-card);
                        box-shadow: var(--lp-shadow);
                        transition: box-shadow .15s ease;
                    }

                    .lp-subsection:hover {
                        box-shadow: var(--lp-shadow-hover);
                    }

                    .lp-subsection-title {
                        font-size: 0.8rem;
                        font-weight: 700;
                        color: var(--lp-primary-dark);
                        background: var(--lp-primary-soft);
                        padding: 10px 14px;
                        border-bottom: 1px solid var(--lp-border-soft);
                        letter-spacing: .01em;
                    }

                    .lp-subsection-body {
                        padding: 14px;
                        display: flex;
                        flex-direction: column;
                        gap: 10px;
                    }

                    .lp-pair-label {
                        flex: 0 0 120px;
                        font-size: 0.78rem;
                        font-weight: 600;
                        color: var(--lp-text-muted);
                        padding-left: 8px;
                    }

                    @media (max-width: 768px) {
                        .lp-pair-label {
                            flex-basis: 80px;
                        }
                    }

                    .lp-dt-grup {
                        width: 150px;
                        text-align: center;
                        vertical-align: middle;
                        font-weight: 700;
                        color: var(--lp-primary-dark);
                        background: var(--lp-primary-soft);
                    }

                    /* ---------- Tabel: lebih rapi, rounded, zebra ---------- */
                    #tabPanelBuatLaporan .table-responsive-custom {
                        border: 1px solid var(--lp-border);
                        border-radius: var(--lp-radius);
                        overflow: hidden;
                        box-shadow: var(--lp-shadow);
                    }

                    #tabPanelBuatLaporan .table-custom {
                        margin: 0 !important;
                        border-collapse: separate;
                        border-spacing: 0;
                    }

                    #tabPanelBuatLaporan .table-custom thead th {
                        background: var(--lp-bg-alt);
                        color: var(--lp-text);
                        font-size: 0.74rem;
                        font-weight: 700;
                        text-transform: uppercase;
                        letter-spacing: .03em;
                        border-bottom: 1px solid var(--lp-border);
                        position: sticky;
                        top: 0;
                        z-index: 2;
                    }

                    #tabPanelBuatLaporan .table-custom tbody tr {
                        transition: background-color .12s ease;
                    }

                    #tabPanelBuatLaporan .table-custom tbody tr:nth-child(even) {
                        background: #fafbff;
                    }

                    #tabPanelBuatLaporan .table-custom tbody tr:hover {
                        background: var(--lp-primary-soft) !important;
                    }

                    #tabPanelBuatLaporan .table-custom td,
                    #tabPanelBuatLaporan .table-custom th {
                        vertical-align: middle;
                        padding: 8px 10px;
                    }

                    /* baris judul kelompok di dalam tabel (background eef2ff) dibuat lebih tegas */
                    #tabPanelBuatLaporan .table-custom td[style*="background:#eef2ff"] {
                        background: var(--lp-primary-soft) !important;
                        border-top: 1px solid var(--lp-primary-soft-2);
                        border-bottom: 1px solid var(--lp-primary-soft-2);
                    }

                    /* ---------- Toggle status (Baik/Buruk, Memenuhi/Tidak, dsb) ----------
       Semua checkbox status pakai class lp-*-chk -- ubah tampilan checkbox
       polos jadi pill/toggle yang lebih enak dipencet, tanpa ubah HTML/JS. */
                    #tabPanelBuatLaporan input[class$="-chk"],
                    #tabPanelBuatLaporan input.lp-dcp-chk,
                    #tabPanelBuatLaporan input.lp-cek-chk {
                        appearance: none;
                        -webkit-appearance: none;
                        width: 26px;
                        height: 26px;
                        border: 2px solid var(--lp-border);
                        border-radius: 7px;
                        background: #fff;
                        cursor: pointer;
                        position: relative;
                        transition: all .15s ease;
                        display: inline-block;
                        vertical-align: middle;
                    }

                    #tabPanelBuatLaporan input[class$="-chk"]:hover,
                    #tabPanelBuatLaporan input.lp-dcp-chk:hover,
                    #tabPanelBuatLaporan input.lp-cek-chk:hover {
                        border-color: var(--lp-primary);
                    }

                    #tabPanelBuatLaporan input[class$="-chk"]:checked,
                    #tabPanelBuatLaporan input.lp-dcp-chk:checked,
                    #tabPanelBuatLaporan input.lp-cek-chk:checked {
                        background: var(--lp-primary);
                        border-color: var(--lp-primary);
                    }

                    #tabPanelBuatLaporan input[class$="-chk"]:checked::after,
                    #tabPanelBuatLaporan input.lp-dcp-chk:checked::after,
                    #tabPanelBuatLaporan input.lp-cek-chk:checked::after {
                        content: "✓";
                        position: absolute;
                        inset: 0;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: #fff;
                        font-size: 15px;
                        font-weight: 700;
                    }

                    /* radio 3-status (Pengukuran Tegangan lama) juga dipercantik dikit */
                    #tabPanelBuatLaporan input[type="radio"][name^="tegangan_status"] {
                        accent-color: var(--lp-primary);
                        width: 15px;
                        height: 15px;
                        cursor: pointer;
                    }

                    /* ---------- Wizard (stepper) ---------- */
                    .lp-wizard-nav {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 16px;
                        margin-bottom: 20px;
                        padding: 14px 20px;
                        background: linear-gradient(135deg, #ffffff 0%, var(--lp-bg-alt) 100%);
                        border: 1px solid var(--lp-border);
                        border-radius: 14px;
                        box-shadow: 0 2px 10px rgba(15, 23, 42, .06);
                        position: sticky;
                        top: 8px;
                        z-index: 20;
                    }

                    .lp-wizard-nav .lp-wizard-title {
                        font-weight: 800;
                        font-size: 1.1rem;
                        color: var(--lp-text);
                    }

                    .lp-wizard-progress {
                        font-size: 0.82rem;
                        color: var(--lp-text-muted);
                        margin-top: 2px;
                        font-weight: 500;
                    }

                    .lp-wizard-dots {
                        display: flex;
                        gap: 7px;
                        flex-wrap: wrap;
                        max-width: 280px;
                        justify-content: flex-end;
                    }

                    .lp-wizard-dot {
                        width: 11px;
                        height: 11px;
                        border-radius: 50%;
                        background: #cbd5e1;
                        cursor: pointer;
                        border: none;
                        padding: 0;
                        transition: transform .15s ease, background-color .15s ease;
                    }

                    .lp-wizard-dot:hover {
                        transform: scale(1.25);
                    }

                    .lp-wizard-dot.active {
                        background: var(--lp-primary);
                        box-shadow: 0 0 0 4px rgba(79, 70, 229, .18);
                    }

                    .lp-wizard-dot.done {
                        background: #a5b4fc;
                    }

                    .lp-step {
                        display: none;
                        animation: lpFadeIn .18s ease;
                    }

                    .lp-step.active {
                        display: block;
                    }

                    @keyframes lpFadeIn {
                        from {
                            opacity: 0;
                            transform: translateY(4px);
                        }

                        to {
                            opacity: 1;
                            transform: translateY(0);
                        }
                    }

                    .lp-wizard-btns {
                        display: flex;
                        justify-content: space-between;
                        gap: 10px;
                        margin-top: 20px;
                        padding-top: 16px;
                        border-top: 1px dashed var(--lp-border);
                    }

                    /* ---------- Tombol aksi konsisten ---------- */
                    #tabPanelBuatLaporan .btn-primary-custom,
                    #tabPanelBuatLaporan .btn-secondary-custom {
                        border-radius: var(--lp-radius-sm);
                        font-weight: 600;
                        transition: transform .1s ease, box-shadow .15s ease;
                    }

                    #tabPanelBuatLaporan .btn-primary-custom:hover,
                    #tabPanelBuatLaporan .btn-secondary-custom:hover {
                        transform: translateY(-1px);
                        box-shadow: 0 4px 10px rgba(15, 23, 42, .10);
                    }

                    .lp-form-actions {
                        padding: 16px;
                        background: var(--lp-bg-alt);
                        border: 1px solid var(--lp-border);
                        border-radius: var(--lp-radius);
                    }

                    /* ============================================================
   RESPONSIVE — Buat Laporan Pemeriksaan
   ============================================================ */

                    /* Tablet ke bawah */
                    @media (max-width: 992px) {
                        .lp-wizard-nav {
                            top: 4px;
                        }
                    }

                    /* HP & tablet kecil (<=768px) */
                    @media (max-width: 768px) {

                        /* Baris "label kiri - input kanan" jadi ditumpuk vertikal */
                        #tabPanelBuatLaporan .d-flex.gap-2:has(> .lp-field-label),
                        #tabPanelBuatLaporan .d-flex.gap-2:has(> .lp-pair-label) {
                            flex-direction: column;
                            align-items: stretch !important;
                            gap: 6px !important;
                        }

                        #tabPanelBuatLaporan .lp-field-label,
                        #tabPanelBuatLaporan .lp-pair-label {
                            width: 100% !important;
                            flex: 0 0 auto !important;
                            padding-top: 0;
                            padding-left: 0 !important;
                        }

                        /* Textarea keterangan tidak perlu dibatasi lebar di HP */
                        #tabPanelBuatLaporan .lp-textarea-ket {
                            max-width: 100%;
                            min-width: 0;
                        }

                        /* Wizard nav: judul & dots ditumpuk, tidak sticky (biar tidak menutupi konten) */
                        .lp-wizard-nav {
                            flex-direction: column;
                            align-items: flex-start;
                            gap: 10px;
                            position: static;
                        }

                        .lp-wizard-dots {
                            max-width: 100%;
                            justify-content: flex-start;
                        }

                        .lp-wizard-btns {
                            flex-direction: column-reverse;
                            gap: 8px;
                        }

                        .lp-wizard-btns .btn-primary-custom,
                        .lp-wizard-btns .btn-secondary-custom {
                            width: 100%;
                            justify-content: center;
                        }

                        /* Tombol Update Preview / Simpan & Buat Laporan full-width, ditumpuk */
                        #tabPanelBuatLaporan .lp-form-actions.d-flex.gap-2 {
                            flex-direction: column;
                        }

                        #tabPanelBuatLaporan .lp-form-actions .btn-primary-custom,
                        #tabPanelBuatLaporan .lp-form-actions .btn-secondary-custom {
                            width: 100%;
                            justify-content: center;
                        }

                        /* Tabel: biarkan scroll horizontal DI DALAM kartu, bukan memaksa
       kolom mengecil sampai tidak terbaca */
                        #tabPanelBuatLaporan .table-responsive-custom {
                            overflow-x: auto;
                            -webkit-overflow-scrolling: touch;
                        }

                        #tabPanelBuatLaporan .table-custom {
                            min-width: 640px;
                        }

                        .lp-section-title {
                            font-size: 0.92rem;
                            padding: 10px 12px;
                            margin: 20px 0 12px 0;
                            flex-wrap: wrap;
                        }

                        .lp-subsection-body {
                            padding: 12px 10px;
                        }

                        /* No Urut Laporan (input + suffix) ditumpuk di HP */
                        .nomor-surat-group {
                            flex-direction: column;
                            align-items: stretch !important;
                        }

                        .nomor-surat-suffix {
                            white-space: normal !important;
                            text-align: left;
                        }

                        /* Tombol di toolbar tab Daftar Laporan / Template (search + tombol) */
                        #tabPanelDaftarLaporan .table-toolbar,
                        #tabPanelTemplateLaporan .table-toolbar {
                            flex-direction: column;
                            align-items: stretch !important;
                            gap: 10px;
                        }

                        #tabPanelDaftarLaporan .table-toolbar-actions,
                        #tabPanelTemplateLaporan .table-toolbar-actions {
                            flex-direction: column;
                            align-items: stretch !important;
                        }

                        #tabPanelDaftarLaporan .table-toolbar-actions .btn-primary-custom,
                        #tabPanelDaftarLaporan .table-toolbar-actions .btn-secondary-custom,
                        #tabPanelTemplateLaporan .table-toolbar-actions .btn-primary-custom {
                            width: 100%;
                            justify-content: center;
                        }

                        #tabPanelDaftarLaporan .table-responsive-custom,
                        #tabPanelTemplateLaporan .table-responsive-custom {
                            overflow-x: auto;
                        }

                        #tabPanelDaftarLaporan .table-custom,
                        #tabPanelTemplateLaporan .table-custom {
                            min-width: 640px;
                        }
                    }

                    /* HP kecil (<=480px) */
                    @media (max-width: 480px) {
                        #tabPanelBuatLaporan {
                            font-size: 0.78rem;
                        }

                        .lp-wizard-nav .lp-wizard-title {
                            font-size: 1rem;
                        }

                        .lp-section-title {
                            font-size: 0.85rem;
                            gap: 6px;
                        }

                        #tabPanelBuatLaporan .row.g-3.mb-2 {
                            row-gap: 10px;
                        }
                    }

                    /* Modal upload (Template & Laporan Manual) — biar tidak overflow di HP */
                    @media (max-width: 576px) {
                        .arp-modal-box {
                            width: 94vw !important;
                            max-width: 94vw !important;
                            margin: 0 auto;
                        }

                        .arp-modal-body .row.g-3>[class*="col-"] {
                            margin-bottom: 8px;
                        }
                    }
                </style>
                <h5 class="fw-bold mb-3">
                    <?= $laporanEdit ? 'Edit Laporan Pemeriksaan' : 'Buat Laporan Pemeriksaan Baru' ?>
                </h5>
                <?php if ($laporanEdit): ?>
                    <div class="alert alert-warning-custom text-xs mb-3" style="display:flex; gap:8px; align-items:center;">
                        <i class="bi bi-pencil-square fs-5"></i>
                        <div>
                            Mengedit laporan <strong><?= e($laporanEdit['nomor_laporan']) ?></strong>. Nomor tetap, file
                            Word akan dibuat ulang.
                            Foto NDT tidak tersimpan, jadi unggah ulang bila ingin ditampilkan.
                            <a href="pemeriksaan.php?tab=daftar" class="ms-2"
                                data-arp-loading="Kembali ke daftar laporan...">Batal edit</a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($jadwalAktif)): ?>
                    <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;
                padding:14px 16px; margin-bottom:16px;
                background:#fffbeb; border:1px solid #fcd34d; border-left:5px solid #f59e0b;
                border-radius:10px;">
                        <a href="upload.php?tab=proses" title="Kembali ke daftar proses" aria-label="Kembali" style="display:inline-flex; align-items:center; justify-content:center;
                   width:38px; height:38px; flex:0 0 38px;
                   background:#ffffff; color:#92400e; font-size:1.1rem; text-decoration:none;
                   border:1px solid #fcd34d; border-radius:8px;" data-arp-loading="Kembali ke daftar proses...">
                            <i class="bi bi-arrow-left"></i>
                        </a>
                        <div style="flex:1 1 260px; color:#1e293b; font-size:0.92rem; line-height:1.5;">
                            <span style="font-weight:700; color:#92400e;">Pemeriksaan:</span>
                            <strong style="color:#0f172a;"><?= e($jadwalAktif['nama_perusahaan']) ?></strong>
                            <span
                                style="font-weight:600;">(<?= date('d-m-Y', strtotime($jadwalAktif['tanggal_pemeriksaan'])) ?>)</span>.
                            Setelah disimpan, selesaikan di tab <strong style="color:#0f172a;">Diproses</strong>.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-2">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold mb-2">Bidang Objek</label>
                        <select id="lp-pilih-kategori" class="select-custom" <?= ($editId || $pemeriksaanId) ? 'disabled' : '' ?>>
                            <option value="">-- Pilih bidang objek --</option>
                            <?php foreach ($daftar_kategori_objek as $k): ?>
                                <option value="<?= (int) $k['id_kategori'] ?>" <?= $idKategoriTerpilih === (int) $k['id_kategori'] ? 'selected' : '' ?>>
                                    <?= e($k['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold mb-2">Unit Objek</label>
                        <select id="lp-pilih-jenis" class="select-custom" <?= ($editId || $pemeriksaanId || !$idKategoriTerpilih) ? 'disabled' : '' ?>>
                            <option value="">-- Pilih unit objek --</option>
                            <?php foreach ($daftar_jenis_objek_semua as $j): ?>
                                <?php if ((int) $j['id_kategori'] === $idKategoriTerpilih): ?>
                                    <option value="<?= (int) $j['id_jenis'] ?>" <?= $idJenisTerpilih === (int) $j['id_jenis'] ? 'selected' : '' ?>>
                                        <?= e($j['nama_objek']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold mb-2">Template</label>
                        <select id="lp-pilih-template" class="select-custom" <?= ($editId || !$idJenisTerpilih) ? 'disabled' : '' ?>>
                            <option value="">-- Pilih template --</option>
                            <?php foreach ($daftar_template_laporan as $t): ?>
                                <?php if ((int) $t['id_kategori'] === $idKategoriTerpilih && (int) $t['id_jenis'] === $idJenisTerpilih): ?>
                                    <option value="<?= (int) $t['id'] ?>" <?= $templateIdTerpilih === (int) $t['id'] ? 'selected' : '' ?>>
                                        <?= e($t['nama']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="text-secondary text-xs mb-3">
                    Belum ada template untuk kombinasi ini? Upload dulu lewat tab
                    <a href="#"
                        onclick="switchTab('tabPanelTemplateLaporan', document.querySelector('[data-tab-target=tabPanelTemplateLaporan]')); return false;">Upload
                        Template Laporan</a>.
                </p>

                <script>
                    (function () {
                        function pindah(idKategori, idJenis, templateId) {
                            var url = 'pemeriksaan.php?tab=buat';
                            if (idKategori) url += '&id_kategori=' + idKategori;
                            if (idJenis) url += '&id_jenis=' + idJenis;
                            if (templateId) url += '&template_id=' + templateId;
                            <?php if (!empty($pemeriksaanId)): ?>
                                url += '&pemeriksaan_id=<?= (int) $pemeriksaanId ?>';
                            <?php endif; ?>
                            if (window.arpShowLoader) window.arpShowLoader('Memuat form laporan...');
                            window.location.href = url;
                        }
                        var selKategori = document.getElementById('lp-pilih-kategori');
                        var selJenis = document.getElementById('lp-pilih-jenis');
                        var selTemplate = document.getElementById('lp-pilih-template');
                        if (selKategori) selKategori.addEventListener('change', function () { pindah(this.value, '', ''); });
                        if (selJenis) selJenis.addEventListener('change', function () { pindah(selKategori.value, this.value, ''); });
                        if (selTemplate) selTemplate.addEventListener('change', function () { pindah(selKategori.value, selJenis.value, this.value); });
                    })();
                </script>

                <?php if ($templateTerpilih && !$file_template_lp_hilang && $templateTerpilih['format'] === 'word_pdf'): ?>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <a class="btn-secondary-custom" style="font-size:0.75rem;"
                            href="https://docs.google.com/document/d/<?= e($templateTerpilih['drive_file_id']) ?>/edit"
                            target="_blank" data-arp-loading="Membuka Google Drive...">
                            <i class="bi bi-file-earmark-word"></i> Lihat &amp; Edit Word di Drive
                        </a>
                        <form method="POST" action="pemeriksaan.php" class="d-inline">
                            <input type="hidden" name="aksi" value="sinkron_template_laporan">
                            <input type="hidden" name="redirect_tab" value="buat">
                            <input type="hidden" name="id_kategori" value="<?= (int) $idKategoriTerpilih ?>">
                            <input type="hidden" name="id_jenis" value="<?= (int) $idJenisTerpilih ?>">
                            <input type="hidden" name="template_id" value="<?= (int) $templateTerpilih['id'] ?>">
                            <button type="submit" class="btn-secondary-custom" style="font-size:0.75rem;"
                                data-arp-loading="Menyinkronkan field dari Word...">
                                <i class="bi bi-arrow-repeat"></i> Sinkronkan Field dari Word
                            </button>
                        </form>
                        <small class="text-secondary text-xs">Sudah edit di Word/Drive? Klik ini biar form ikut
                            update.</small>
                    </div>
                <?php endif; ?>

                <?php if ($templateTerpilih && $file_template_lp_hilang): ?>
                    <div class="alert alert-danger-custom text-xs">
                        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                        <div>File template ini tidak ditemukan di Drive. Upload ulang lewat tab Upload Template Laporan.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($templateTerpilih && !$file_template_lp_hilang && $templateTerpilih['format'] === 'word_pdf'): ?>
                    <hr>
                    <form method="POST" action="pemeriksaan.php" id="form-buat-laporan" enctype="multipart/form-data">
                        <input type="hidden" name="aksi" value="generate_laporan">
                        <input type="hidden" name="id_kategori" value="<?= (int) $idKategoriTerpilih ?>">
                        <input type="hidden" name="id_jenis" value="<?= (int) $idJenisTerpilih ?>">
                        <input type="hidden" name="template_id" value="<?= (int) $templateTerpilih['id'] ?>">
                        <?php if (!empty($pemeriksaanId)): ?>
                            <input type="hidden" name="pemeriksaan_id" value="<?= (int) $pemeriksaanId ?>">
                            <input type="hidden" name="jenis_pemeriksaan"
                                value="<?= e($_POST['jenis_pemeriksaan'] ?? 'Pemeriksaan Berkala') ?>">
                        <?php endif; ?>
                        <?php if ($editId): ?>
                            <input type="hidden" name="edit_id" value="<?= (int) $editId ?>">
                        <?php endif; ?>
                        <input type="hidden" name="klien_id" id="lp-input-klien-id"
                            value="<?= (int) ($_POST['klien_id'] ?? 0) ?: '' ?>">
                        <input type="hidden" name="wizard_step" id="lp-wizard-step"
                            value="<?= (int) ($_POST['wizard_step'] ?? 0) ?>">
                        <div class="lp-section-title"> I. Data Umum</div>
                        <div class="d-flex flex-column gap-2 mb-3">
                            <div class="d-flex align-items-start gap-2">
                                <label class="form-label fw-semibold mb-0 text-xs lp-field-label">No Urut Laporan</label>
                                <div style="flex:1 1 auto;">
                                    <div class="nomor-surat-group">
                                        <input type="text" name="no_urut_manual"
                                            class="form-control-custom nomor-surat-input text-xs" <?= $editId ? 'readonly' : '' ?> value="<?= e($_POST['no_urut_manual'] ?? '') ?>"
                                            placeholder="<?= e(explode('/', $preview_nomor_laporan)[0] ?? '001') ?>">
                                        <div
                                            class="form-control-custom field-readonly text-secondary nomor-surat-suffix text-xs">
                                            /<?= e(strtoupper($templateTerpilih['kode_laporan'])) ?>/ARP/<?= e(lp_bulan_romawi()) ?>/<?= date('Y') ?>
                                        </div>
                                    </div>
                                    <small class="text-secondary text-xs d-block mt-1">Kosongkan untuk otomatis.</small>
                                </div>
                            </div>
                            <!-- <div class="d-flex align-items-center gap-2">
                                <label class="form-label fw-semibold mb-0 text-xs lp-field-label">Jenis Pemeriksaan</label>
                                <select name="jenis_pemeriksaan" class="select-custom text-xs" style="flex:1 1 auto;">
                                    <option value="Pemeriksaan Berkala">Pemeriksaan Berkala</option>
                                    <option value="Pemeriksaan Baru">Pemeriksaan Baru</option>
                                </select>
                            </div> -->
                        </div>

                        <?php
                        $adaFieldPerusahaan = false;
                        foreach ($fields_dinamis_lp as $f) {
                            if ($f['field'] === 'nama_perusahaan') {
                                $adaFieldPerusahaan = true;
                                break;
                            }
                        }
                        ?>

                        <div class="d-flex flex-column gap-1 mb-2">
                            <?php if (!$adaFieldPerusahaan): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <label class="form-label fw-semibold mb-0 text-xs lp-field-label">Nama Perusahaan</label>
                                    <input type="text" name="nama_perusahaan" id="lp-cari-perusahaan"
                                        class="form-control-custom text-xs" autocomplete="off" style="flex:1 1 auto;"
                                        value="<?= e($_POST['nama_perusahaan'] ?? '') ?>"
                                        placeholder="Ketik nama perusahaan...">
                                </div>
                            <?php endif; ?>
                            <!-- <div class="d-flex align-items-center gap-2">
                                <label class="form-label fw-semibold mb-0 text-xs lp-field-label">Unit Objek
                                    (spesifik)</label>
                                <input type="text" name="unit_objek_input" class="form-control-custom text-xs"
                                    style="flex:1 1 auto;"
                                    value="<?= e($_POST['unit_objek_input'] ?? $templateTerpilih['nama_objek']) ?>"
                                    placeholder="Cth: Forklift Unit 2">
                            </div> -->
                        </div>

                        <script>
                            (function () {
                                var inputCari = document.getElementById('lp-cari-perusahaan');
                                var hiddenId = document.getElementById('lp-input-klien-id');
                                if (!inputCari) return;
                                var timer = null, box = null;
                                function tutup() { if (box) { box.remove(); box = null; } }
                                inputCari.addEventListener('input', function () {
                                    hiddenId.value = '';
                                    clearTimeout(timer);
                                    var q = inputCari.value.trim();
                                    if (q === '') { tutup(); return; }
                                    timer = setTimeout(function () {
                                        fetch('pemeriksaan.php?ajax=cari_klien_lp&q=' + encodeURIComponent(q))
                                            .then(r => r.json()).then(function (daftar) {
                                                tutup();
                                                if (!daftar.length) return;
                                                box = document.createElement('div');
                                                box.style.cssText = 'position:absolute;z-index:2000;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 20px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;font-size:.85rem;';
                                                var rect = inputCari.getBoundingClientRect();
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
                                                        inputCari.value = k.nama_perusahaan;
                                                        hiddenId.value = k.id;
                                                        tutup();
                                                    });
                                                    box.appendChild(item);
                                                });
                                            });
                                    }, 250);
                                });
                                inputCari.addEventListener('blur', function () { setTimeout(tutup, 150); });
                            })();
                        </script>

                        <?php if (empty($fields_dinamis_lp) && empty($fields_tabel_lp) && empty($fields_checklist_lp) && empty($fields_visual_lp)): ?>
                            <div class="alert alert-danger-custom text-xs">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <div>Template ini belum punya placeholder <code>${...}</code> yang terbaca.</div>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($fields_dinamis_lp as $f): ?>
                                <?php if (in_array($f['field'], $LP_ARUS_INPUT_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], $LP_PROTEKSI_INPUT_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], $LP_RST_INPUT_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], $LP_KABEL_INPUT_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], $LP_CHK_BEJANA_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], LP_THK_INPUT_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], $LP_DATA_TEKNIS_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], $LP_HASIL_UKUR_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], LP_PND_INPUT_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], $LP_DT_TABEL_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], LP_KMP_INPUT_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], LP_AKM_INPUT_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], $LP_DTK_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], lp_vtg_semua_field(), true))
                                    continue; ?>
                                <?php if (in_array($f['field'], $LP_DTR_FIELDS, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], LP_NDT_FIELD_SEMUA, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], LP_PUJ_FIELD_SEMUA, true))
                                    continue; ?>
                                <?php if (in_array($f['field'], LP_PJN_FIELD_SEMUA, true))
                                    continue; ?>
                                <!-- <<< TAMBAHAN -->

                                <?php if ($f['field'] === 'pelaksana'): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <label
                                            class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($f['label']) ?></label>
                                        <select name="dinamis[<?= e($f['field']) ?>]" class="select-custom text-xs"
                                            style="flex:1 1 auto;">
                                            <option value="">-- Pilih pelaksana --</option>
                                            <?php foreach (LP_PILIHAN_PELAKSANA as $nama): ?>
                                                <option value="<?= e($nama) ?>" <?= ($nilai_dinamis_lp[$f['field']] ?? '') === $nama ? 'selected' : '' ?>>
                                                    <?= e($nama) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php continue; ?>
                                <?php endif; ?>

                                <?php if ($f['field'] === 'izin_pakai'): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <label
                                            class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($f['label']) ?></label>
                                        <select name="dinamis[<?= e($f['field']) ?>]" class="select-custom text-xs"
                                            style="flex:1 1 auto;">
                                            <?php foreach (LP_PILIHAN_IZIN_PAKAI as $opsi): ?>
                                                <option value="<?= e($opsi) ?>" <?= ($nilai_dinamis_lp[$f['field']] ?? '') === $opsi ? 'selected' : '' ?>>
                                                    <?= e($opsi) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php continue; ?>
                                <?php endif; ?>

                                <?php if ($f['field'] === 'klasifikasi'): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <label
                                            class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($f['label']) ?></label>
                                        <select name="dinamis[<?= e($f['field']) ?>]" class="select-custom text-xs"
                                            style="flex:1 1 auto;">
                                            <?php foreach (LP_PILIHAN_KLASIFIKASI as $opsi): ?>
                                                <option value="<?= e($opsi) ?>" <?= ($nilai_dinamis_lp[$f['field']] ?? '') === $opsi ? 'selected' : '' ?>>
                                                    <?= e($opsi) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php continue; ?>
                                <?php endif; ?>

                                <?php if (isset(LP_FIELD_PASANGAN[$f['field']])): ?>
                                    <?php
                                    $defP = LP_FIELD_PASANGAN[$f['field']];
                                    $labelBaris = $defP['label'] ?? null;
                                    ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <label class="form-label fw-semibold mb-0 text-xs lp-field-label">
                                            <?= e($labelBaris ?? $defP['kiri']) ?>
                                        </label>
                                        <input type="text" name="pasangan[<?= e($f['field']) ?>][kiri]"
                                            class="form-control-custom text-xs" style="flex:1 1 0; min-width:0;"
                                            placeholder="<?= $labelBaris ? e($defP['kiri']) : '' ?>"
                                            value="<?= e($nilai_pasangan_lp[$f['field']]['kiri'] ?? '') ?>">

                                        <?php if ($labelBaris === null): ?>
                                            <label
                                                class="form-label fw-semibold mb-0 text-xs lp-pair-label"><?= e($defP['kanan']) ?></label>
                                        <?php endif; ?>

                                        <input type="text" name="pasangan[<?= e($f['field']) ?>][kanan]"
                                            class="form-control-custom text-xs" style="flex:1 1 0; min-width:0;"
                                            placeholder="<?= $labelBaris ? e($defP['kanan']) : '' ?>"
                                            value="<?= e($nilai_pasangan_lp[$f['field']]['kanan'] ?? '') ?>">
                                    </div>
                                    <?php continue; ?>
                                <?php endif; ?>
                                <?php $isTanggal = lp_is_kolom_tanggal($f['field']); ?>
                                <?php $isMultiline = in_array($f['field'], LP_FIELD_MULTILINE, true); ?>
                                <div class="d-flex align-items-start gap-2">
                                    <label
                                        class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($f['label']) ?></label>
                                    <?php if ($isMultiline): ?>
                                        <textarea name="dinamis[<?= e($f['field']) ?>]" rows="2"
                                            class="form-control-custom lp-textarea-ket text-xs"
                                            style="flex:1 1 auto; max-width:none;"
                                            placeholder="<?= e(LP_PLACEHOLDER_FIELD[$f['field']] ?? '') ?>"
                                            oninput="lpAutoGrowTextarea(this)"><?= e($nilai_dinamis_lp[$f['field']] ?? '') ?></textarea>
                                    <?php else: ?>
                                        <input type="<?= $isTanggal ? 'date' : 'text' ?>" name="dinamis[<?= e($f['field']) ?>]"
                                            <?= $f['field'] === 'nama_perusahaan' ? 'id="lp-cari-perusahaan" autocomplete="off"' : '' ?> placeholder="<?= e(LP_PLACEHOLDER_FIELD[$f['field']] ?? '') ?>"
                                            class="form-control-custom text-xs" style="flex:1 1 auto;"
                                            value="<?= e($nilai_dinamis_lp[$f['field']] ?? '') ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($adaDtr): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">II. DATA TEKNIS</div>
                                <?= lp_dtr_render_tabel($nilai_dinamis_lp, $nilai_pasangan_lp) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($adaDtk): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">II. Data Teknik</div>
                                <?= lp_dtk_render_tabel($nilai_dinamis_lp, $nilai_pasangan_lp) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fields_dcp_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">III. DATA CHECKLIST PEMERIKSAAN</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" style="vertical-align:middle; text-align:center;">Komponen</th>
                                                <th colspan="2" style="text-align:center;">Kondisi</th>
                                                <th rowspan="2" style="width:260px; vertical-align:middle; text-align:center;">
                                                    Keterangan</th>
                                            </tr>
                                            <tr>
                                                <th style="width:80px; text-align:center;">Baik</th>
                                                <th style="width:80px; text-align:center;">Buruk</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_dcp_lp as $g): ?>
                                                <?php
                                                $mainDcp = $g['main'] ?? null;
                                                $subsDcp = $g['subs'] ?? [];
                                                ?>
                                                <tr>
                                                    <?php if ($mainDcp): ?>
                                                        <td><?= e($g['label']) ?></td>
                                                        <?= lp_dcp_sel_kondisi($mainDcp, $nilai_dcp_status_lp, $nilai_dcp_ket_lp) ?>
                                                    <?php else: ?>
                                                        <td colspan="4" style="font-weight:600; background:#f8fafc;">
                                                            <?= e($g['label']) ?>
                                                        </td>
                                                    <?php endif; ?>
                                                </tr>
                                                <?php foreach ($subsDcp as $s): ?>
                                                    <tr>
                                                        <td style="padding-left:28px;"><?= e($s['sub']) ?>. <?= e($s['label']) ?></td>
                                                        <?= lp_dcp_sel_kondisi($s, $nilai_dcp_status_lp, $nilai_dcp_ket_lp) ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Klik "Baik" atau "Buruk" untuk mencentang salah satu. Klik lagi pada kotak yang sudah
                                    tercentang
                                    untuk membatalkan pilihan (kosong). Jika tidak ada yang dipilih, kedua kolom otomatis
                                    tercetak "-" di Word.
                                    Keterangan awalnya kosong; isi manual jika perlu, tekan Enter untuk baris baru.
                                </small>
                            </div>
                            <script>
                                (function () {
                                    document.addEventListener('change', function (e) {
                                        var t = e.target;
                                        if (!t.classList || !t.classList.contains('lp-dcp-chk')) return;
                                        if (t.checked) {
                                            document.querySelectorAll('input.lp-dcp-chk[name="' + CSS.escape(t.name) + '"]').forEach(function (chk) {
                                                if (chk !== t) chk.checked = false;
                                            });
                                        }
                                    });
                                })();
                            </script>
                        <?php endif; ?>


                        <?php foreach (LP_TABEL_UJI_DEF as $pfxUji => $defUji): ?>
                            <?php
                            $daftarUji = $fields_tabel_uji_lp[$pfxUji] ?? [];
                            if (empty($daftarUji))
                                continue;
                            ?>
                            <div class="mt-3">
                                <div class="lp-section-title"><?= e($defUji['judul']) ?></div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th style="width:36px; text-align:center;">No</th>
                                                <th><?= e($defUji['kolom_label']) ?></th>
                                                <th style="width:220px;"><?= e($defUji['kolom_hasil']) ?></th>
                                                <th style="width:260px;"><?= e($defUji['kolom2_judul']) ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($daftarUji as $it): ?>
                                                <tr>
                                                    <td style="text-align:center;"><?= (int) $it['no'] ?></td>
                                                    <td><?= e($it['label']) ?></td>
                                                    <td>
                                                        <input type="text" name="dinamis[<?= e($it['field_hasil']) ?>]"
                                                            class="form-control-custom text-xs"
                                                            placeholder="<?= e($defUji['hint_hasil']) ?>"
                                                            value="<?= e($nilai_tabel_uji_lp[$it['field_hasil']] ?? '') ?>">
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($it['field_kol2'])): ?>
                                                            <input type="text" name="dinamis[<?= e($it['field_kol2']) ?>]"
                                                                class="form-control-custom text-xs"
                                                                placeholder="<?= e($defUji['hint_kol2']) ?>"
                                                                value="<?= e($nilai_tabel_uji_lp[$it['field_kol2']] ?? '') ?>">
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Kolom Hasil yang dikosongkan otomatis tercetak "-" di Word.
                                    <?php if ($pfxUji === 'bis'): ?>
                                        Angka murni otomatis ditambah "dB" (76.7 → 76.7 dB).
                                    <?php else: ?>
                                        Keterangan yang dikosongkan tetap kosong.
                                    <?php endif; ?>
                                </small>
                            </div>
                        <?php endforeach; ?>


                        <?php if ($adaVtg): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">D. Pengukuran Tegangan</div>
                                <div class="lp-subsection">
                                    <div class="lp-subsection-title">Pengukuran Panel Listrik
                                        <span class="fw-normal fst-italic">(Electrical Panel Test)</span>
                                    </div>
                                    <div class="lp-subsection-body">
                                        <?php foreach (LP_VTG_GRUP as $judulGrup => $daftarField): ?>
                                            <?php
                                            $adaDiTemplate = array_filter(array_keys($daftarField), fn($k) => array_key_exists($k, $nilai_dinamis_lp));
                                            if (!$adaDiTemplate)
                                                continue;
                                            ?>
                                            <div>
                                                <div class="text-xs fw-semibold mb-1"><?= e($judulGrup) ?></div>
                                                <div class="row g-2">
                                                    <?php foreach ($daftarField as $namaField => $label): ?>
                                                        <?php if (!array_key_exists($namaField, $nilai_dinamis_lp))
                                                            continue; ?>
                                                        <div class="col-6 col-md-2">
                                                            <label
                                                                class="form-label mb-1 text-xs text-secondary"><?= e($label) ?></label>
                                                            <input type="text" name="dinamis[<?= e($namaField) ?>]"
                                                                class="form-control-custom text-xs"
                                                                value="<?= e($nilai_dinamis_lp[$namaField] ?? '') ?>">
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <small class="text-secondary text-xs">
                                            Kosongkan kolom yang tidak diukur, otomatis tercetak "-" di Word.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($adaDataTeknisTabel): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">Data Teknis</div>

                                <?php foreach (LP_DATA_TEKNIS_TABEL as $judulGrup => $barisGrup): ?>
                                    <?php
                                    $rows = lp_dt_baris_aktif($barisGrup, $nilai_dinamis_lp);
                                    if (!$rows)
                                        continue;
                                    ?>
                                    <div class="lp-subsection">
                                        <div class="lp-subsection-title"><?= e($judulGrup) ?></div>
                                        <div class="table-responsive-custom">
                                            <table class="table-custom" style="margin:0;">
                                                <tbody>
                                                    <?php foreach ($rows as $b): ?>
                                                        <?php $subs = $b['sub'] ?? null; ?>
                                                        <?php if ($subs): ?>
                                                            <?php foreach ($subs as $i => $s): ?>
                                                                <tr>
                                                                    <?php if ($i === 0): ?>
                                                                        <td rowspan="<?= count($subs) ?>"
                                                                            style="width:200px; vertical-align:middle;">
                                                                            <?= e($b['label']) ?>
                                                                        </td>
                                                                    <?php endif; ?>
                                                                    <td style="width:140px;"><?= e($s['label']) ?></td>
                                                                    <td><?= lp_dt_input_html($s['field'], $nilai_dinamis_lp, $nilai_pasangan_lp, $s['hint'] ?? '') ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <tr>
                                                                <td colspan="2" style="width:340px;"><?= e($b['label']) ?></td>
                                                                <td><?= lp_dt_input_html($b['field'], $nilai_dinamis_lp, $nilai_pasangan_lp, $b['hint'] ?? '') ?>
                                                                </td>
                                                            </tr>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <small class="text-secondary text-xs">Kolom kosong otomatis tercetak "-" di Word. Satuan (kg,
                                    mm, dll.) ditambahkan otomatis jika hanya diisi angka.</small>
                            </div>
                        <?php endif; ?>

                        <?php if ($adaDataTeknis): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">II. Data Teknis</div>

                                <?php foreach (LP_DATA_TEKNIS_GRUP as $judulGrup => $daftarField): ?>
                                    <?php
                                    $adaDiTemplate = array_filter(array_keys($daftarField), fn($k) => array_key_exists($k, $nilai_dinamis_lp));
                                    if (!$adaDiTemplate)
                                        continue;
                                    ?>
                                    <div class="lp-subsection">
                                        <div class="lp-subsection-title"><?= e($judulGrup) ?></div>
                                        <div class="lp-subsection-body">
                                            <?php if ($judulGrup === 'A. MOTOR DIESEL'): ?>
                                                <small class="text-secondary text-xs">
                                                    No. 1-6 (Merek/Tipe, Pabrik/Negara, Tahun, Klasifikasi, Nomor Seri, Daya)
                                                    diambil otomatis dari Data Umum di atas.
                                                </small>
                                            <?php endif; ?>
                                            <?php foreach ($daftarField as $namaField => $label): ?>
                                                <?php
                                                if (!array_key_exists($namaField, $nilai_dinamis_lp))
                                                    continue;
                                                ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <label class="form-label fw-semibold mb-0 text-xs lp-field-label">
                                                        <?= e($label) ?>
                                                    </label>

                                                    <?php if (isset(LP_FIELD_PASANGAN[$namaField])): ?>
                                                        <?php $defP = LP_FIELD_PASANGAN[$namaField]; ?>
                                                        <input type="text" name="pasangan[<?= e($namaField) ?>][kiri]"
                                                            class="form-control-custom text-xs" style="flex:1 1 0; min-width:0;"
                                                            placeholder="<?= e($defP['kiri']) ?>"
                                                            value="<?= e($nilai_pasangan_lp[$namaField]['kiri'] ?? '') ?>">
                                                        <input type="text" name="pasangan[<?= e($namaField) ?>][kanan]"
                                                            class="form-control-custom text-xs" style="flex:1 1 0; min-width:0;"
                                                            placeholder="<?= e($defP['kanan']) ?>"
                                                            value="<?= e($nilai_pasangan_lp[$namaField]['kanan'] ?? '') ?>">
                                                    <?php else: ?>
                                                        <input type="text" name="dinamis[<?= e($namaField) ?>]"
                                                            class="form-control-custom text-xs" style="flex:1 1 auto;"
                                                            placeholder="<?= e(LP_PLACEHOLDER_FIELD[$namaField] ?? '') ?>"
                                                            value="<?= e($nilai_dinamis_lp[$namaField] ?? '') ?>">
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fields_pvf_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">III. PEMERIKSAAN VISUAL &amp; FUNGSI</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th colspan="2" style="text-align:center;">Komponen &amp; Lokasi</th>
                                                <th rowspan="2" style="vertical-align:middle; text-align:center;">Pemeriksaan
                                                    Komponen</th>
                                                <th colspan="2" style="text-align:center;">Kondisi</th>
                                                <th rowspan="2" style="width:260px; vertical-align:middle; text-align:center;">
                                                    Keterangan</th>
                                            </tr>
                                            <tr>
                                                <th style="width:120px; text-align:center;">Lokasi</th>
                                                <th style="width:140px; text-align:center;">Komponen</th>
                                                <th style="width:110px; text-align:center;">Memenuhi Syarat</th>
                                                <th style="width:130px; text-align:center;">Tidak Memenuhi Syarat</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_pvf_lp as $grup): ?>
                                                <tr>
                                                    <td colspan="6" style="background:#eef2ff; font-weight:700; color:#4338ca;">
                                                        <?= (int) $grup['no'] ?>. <?= e($grup['judul']) ?>
                                                    </td>
                                                </tr>
                                                <?php $rowspanMap = lp_pvf_hitung_rowspan($grup['items']); ?>
                                                <?php foreach ($grup['items'] as $idx => $it): ?>
                                                    <?php
                                                    $stPvf = $nilai_pvf_status_lp[$it['key']] ?? '';
                                                    $rsLok = $rowspanMap[$idx]['lokasi_rowspan'];
                                                    $rsKom = $rowspanMap[$idx]['komponen_rowspan'];
                                                    ?>
                                                    <tr>
                                                        <?php if ($rsLok !== null): ?>
                                                            <td rowspan="<?= $rsLok ?>" style="vertical-align:middle; font-weight:600;">
                                                                <?= e($it['lokasi']) ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if ($rsKom !== null): ?>
                                                            <td rowspan="<?= $rsKom ?>" style="vertical-align:middle;">
                                                                <?= e($it['komponen']) ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td><?= e($it['label']) ?></td>
                                                        <td style="text-align:center;">
                                                            <input type="checkbox" class="lp-pvf-chk"
                                                                name="pvf_status[<?= e($it['key']) ?>]" value="ok" <?= $stPvf === 'ok' ? 'checked' : '' ?>>
                                                        </td>
                                                        <td style="text-align:center;">
                                                            <input type="checkbox" class="lp-pvf-chk"
                                                                name="pvf_status[<?= e($it['key']) ?>]" value="tdk" <?= $stPvf === 'tdk' ? 'checked' : '' ?>>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($it['field_ket'])): ?>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="pvf_ket[<?= e($it['key']) ?>]" rows="1"
                                                                    oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'"><?= e($nilai_pvf_ket_lp[$it['key']] ?? '') ?></textarea>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Klik "Memenuhi Syarat" atau "Tidak Memenuhi Syarat" untuk mencentang salah satu.
                                    Klik lagi pada kotak yang sudah tercentang untuk membatalkan pilihan (kosong).
                                    Jika tidak ada yang dipilih, kedua kolom otomatis tercetak "-" di Word.
                                    Keterangan yang dikosongkan terisi otomatis: kosong.
                                </small>
                            </div>
                            <script>
                                (function () {
                                    document.addEventListener('change', function (e) {
                                        var t = e.target;
                                        if (!t.classList || !t.classList.contains('lp-pvf-chk')) return;
                                        if (t.checked) {
                                            document.querySelectorAll('input.lp-pvf-chk[name="' + CSS.escape(t.name) + '"]').forEach(function (chk) {
                                                if (chk !== t) chk.checked = false;
                                            });
                                        }
                                    });
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if (!empty($fields_vf_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">III. Pemeriksaan Visual &amp; Fungsi</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th colspan="2" style="text-align:center;">Komponen &amp; Lokasi</th>
                                                <th rowspan="2" style="vertical-align:middle; text-align:center;">Pemeriksaan
                                                    Komponen</th>
                                                <th colspan="2" style="text-align:center;">Kondisi</th>
                                                <th rowspan="2" style="width:260px; vertical-align:middle; text-align:center;">
                                                    Keterangan</th>
                                            </tr>
                                            <tr>
                                                <th style="width:120px; text-align:center;">Lokasi</th>
                                                <th style="width:140px; text-align:center;">Komponen</th>
                                                <th style="width:110px; text-align:center;">Memenuhi Syarat</th>
                                                <th style="width:130px; text-align:center;">Tidak Memenuhi Syarat</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_vf_lp as $grup): ?>
                                                <tr>
                                                    <td colspan="6" style="background:#eef2ff; font-weight:700; color:#4338ca;">
                                                        <?= (int) $grup['no'] ?>. <?= e($grup['judul']) ?>
                                                    </td>
                                                </tr>
                                                <?php $rowspanMap = lp_pvf_hitung_rowspan($grup['items']); ?>
                                                <?php foreach ($grup['items'] as $idx => $it): ?>
                                                    <?php
                                                    $stVf = $nilai_vf_status_lp[$it['key']] ?? '';
                                                    $rsLok = $rowspanMap[$idx]['lokasi_rowspan'];
                                                    $rsKom = $rowspanMap[$idx]['komponen_rowspan'];
                                                    $komKosong = trim((string) ($it['komponen'] ?? '')) === '';
                                                    ?>
                                                    <tr>
                                                        <?php if ($rsLok !== null): ?>
                                                            <td rowspan="<?= $rsLok ?>"
                                                                style="vertical-align:middle; text-align:center; font-weight:600;">
                                                                <?= e($it['lokasi']) ?>
                                                            </td>
                                                        <?php endif; ?>

                                                        <?php if ($komKosong): ?>
                                                            <!-- Komponen & Pemeriksaan digabung (mis. "Pemberat (C/W)") -->
                                                            <td colspan="2" style="vertical-align:middle; text-align:center;">
                                                                <?= e($it['label']) ?>
                                                            </td>
                                                        <?php else: ?>
                                                            <?php if ($rsKom !== null): ?>
                                                                <td rowspan="<?= $rsKom ?>" style="vertical-align:middle; text-align:center;">
                                                                    <?= e($it['komponen']) ?>
                                                                </td>
                                                            <?php endif; ?>
                                                            <td><?= e($it['label']) ?></td>
                                                        <?php endif; ?>

                                                        <td style="text-align:center;">
                                                            <input type="checkbox" class="lp-vf-chk"
                                                                name="vf_status[<?= e($it['key']) ?>]" value="ok" <?= $stVf === 'ok' ? 'checked' : '' ?>>
                                                        </td>
                                                        <td style="text-align:center;">
                                                            <input type="checkbox" class="lp-vf-chk"
                                                                name="vf_status[<?= e($it['key']) ?>]" value="tdk" <?= $stVf === 'tdk' ? 'checked' : '' ?>>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($it['field_ket'])): ?>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="vf_ket[<?= e($it['key']) ?>]" rows="1"
                                                                    placeholder="Kosong = otomatis"
                                                                    oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'"><?= e($nilai_vf_ket_lp[$it['key']] ?? '') ?></textarea>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Klik "Memenuhi Syarat" atau "Tidak Memenuhi Syarat" untuk mencentang salah satu.
                                    Klik lagi pada kotak yang sudah tercentang untuk membatalkan pilihan (kosong).
                                    Jika tidak ada yang dipilih, kedua kolom otomatis tercetak "-" di Word.
                                    Keterangan yang dikosongkan terisi otomatis: "Baik" (Memenuhi Syarat) atau "Tidak ditemukan"
                                    (tidak dipilih).
                                </small>
                            </div>
                            <script>
                                (function () {
                                    document.addEventListener('change', function (e) {
                                        var t = e.target;
                                        if (!t.classList || !t.classList.contains('lp-vf-chk')) return;
                                        if (t.checked) {
                                            document.querySelectorAll('input.lp-vf-chk[name="' + CSS.escape(t.name) + '"]').forEach(function (chk) {
                                                if (chk !== t) chk.checked = false;
                                            });
                                        }
                                    });
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if ($adaNdt): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">IV. Pemeriksaan Tidak Merusak (NDT)</div>

                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <label class="form-label fw-semibold mb-0 text-xs lp-field-label">Jenis NDT</label>
                                    <input type="text" name="dinamis[ndt_jenis]" class="form-control-custom text-xs"
                                        style="flex:1 1 auto;" placeholder="Contoh: Penetrant (magnaflux)"
                                        value="<?= e($nilai_dinamis_lp['ndt_jenis'] ?? '') ?>">
                                </div>

                                <div class="d-flex justify-content-end mb-2">
                                    <button type="button" class="btn-secondary-custom" style="font-size:0.75rem;"
                                        onclick="ndtTambahBaris()">
                                        <i class="bi bi-plus-lg"></i> Tambah Baris NDT
                                    </button>
                                </div>

                                <?php
                                $nilaiNdt = $_POST['ndt'] ?? [];
                                if (empty($nilaiNdt))
                                    $nilaiNdt = [['bagian' => '', 'lokasi' => '', 'cacat' => '', 'ket' => '']];
                                ?>
                                <div class="table-responsive-custom">
                                    <table class="table-custom" id="ndt-tabel">
                                        <thead>
                                            <tr>
                                                <th style="width:36px; text-align:center;">No</th>
                                                <th>Bagian Yang Diperiksa</th>
                                                <th>Lokasi</th>
                                                <th style="width:170px; text-align:center;">Cacat</th>
                                                <th>Keterangan</th>
                                                <th style="width:40px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="ndt-tabel-body">
                                            <?php foreach ($nilaiNdt as $idx => $baris): ?>
                                                <tr class="ndt-baris" data-idx="<?= (int) $idx ?>">
                                                    <td class="ndt-nomor" style="text-align:center;"><?= $idx + 1 ?></td>
                                                    <td>
                                                        <input type="text" name="ndt[<?= (int) $idx ?>][bagian]"
                                                            class="form-control-custom text-xs"
                                                            value="<?= e($baris['bagian'] ?? '') ?>"
                                                            placeholder="Contoh: Sambungan Lasan">
                                                    </td>
                                                    <td>
                                                        <input type="text" name="ndt[<?= (int) $idx ?>][lokasi]"
                                                            class="form-control-custom text-xs"
                                                            value="<?= e($baris['lokasi'] ?? '') ?>" placeholder="Contoh: Dump">
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <div class="d-flex align-items-center justify-content-center gap-3">
                                                            <label class="d-flex align-items-center gap-1 text-xs mb-0"
                                                                style="white-space:nowrap;">
                                                                <input type="checkbox" class="lp-ndt-chk"
                                                                    name="ndt[<?= (int) $idx ?>][cacat]" value="ada"
                                                                    <?= ($baris['cacat'] ?? '') === 'ada' ? 'checked' : '' ?>> Ada
                                                            </label>
                                                            <label class="d-flex align-items-center gap-1 text-xs mb-0"
                                                                style="white-space:nowrap;">
                                                                <input type="checkbox" class="lp-ndt-chk"
                                                                    name="ndt[<?= (int) $idx ?>][cacat]" value="tidak"
                                                                    <?= ($baris['cacat'] ?? '') === 'tidak' ? 'checked' : '' ?>> Tidak
                                                                Ada
                                                            </label>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <textarea class="form-control-custom lp-textarea-ket"
                                                            name="ndt[<?= (int) $idx ?>][ket]" rows="1"
                                                            oninput="lpAutoGrowTextarea(this)"><?= e($baris['ket'] ?? '') ?></textarea>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <button type="button" class="btn btn-outline-danger btn-sm py-1"
                                                            style="font-size:0.7rem;" title="Hapus baris"
                                                            onclick="ndtHapusBaris(this)">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">Kolom yang kosong otomatis tercetak "-" di Word.</small>
                            </div>
                            <script>
                                (function () {
                                    function ndtRenumber() {
                                        document.querySelectorAll('#ndt-tabel-body .ndt-baris').forEach(function (box, idx) {
                                            box.querySelector('.ndt-nomor').textContent = idx + 1;
                                            box.querySelectorAll('[name^="ndt["]').forEach(function (el) {
                                                el.name = el.name.replace(/^ndt\[\d+\]/, 'ndt[' + idx + ']');
                                            });
                                        });
                                    }
                                    // Pastikan fungsi ini selalu ada, walau blok Data Checklist Pemeriksaan
                                    // (yang biasanya mendefinisikannya) sedang tidak tampil di template ini.
                                    if (typeof window.lpAutoGrowTextarea !== 'function') {
                                        window.lpAutoGrowTextarea = function (el) {
                                            el.style.height = 'auto';
                                            el.style.height = (el.scrollHeight) + 'px';
                                        };
                                    }

                                    window.ndtTambahBaris = function () {
                                        var tbody = document.getElementById('ndt-tabel-body');
                                        var semuaBaris = tbody.querySelectorAll('.ndt-baris');
                                        var acuan = semuaBaris[semuaBaris.length - 1]; // clone dari baris TERAKHIR, bukan pertama
                                        if (!acuan) return;

                                        var baru = acuan.cloneNode(true);
                                        baru.querySelectorAll('input[type=text]').forEach(function (el) { el.value = ''; });
                                        baru.querySelectorAll('textarea').forEach(function (el) {
                                            el.value = '';
                                            el.removeAttribute('style');     // buang tinggi bawaan hasil auto-grow baris sumber
                                            lpAutoGrowTextarea(el);          // hitung ulang tinggi standar (kosong)
                                        });
                                        baru.querySelectorAll('input.lp-ndt-chk').forEach(function (el) { el.checked = false; });

                                        tbody.appendChild(baru);
                                        ndtRenumber();
                                    };
                                    window.ndtHapusBaris = function (btn) {
                                        var tbody = document.getElementById('ndt-tabel-body');
                                        var baris = btn.closest('tr.ndt-baris');
                                        if (!tbody || !baris) return;
                                        if (tbody.querySelectorAll('tr.ndt-baris').length <= 1) {
                                            baris.querySelectorAll('input[type=text], textarea').forEach(function (el) { el.value = ''; });
                                            baris.querySelectorAll('input.lp-ndt-chk').forEach(function (el) { el.checked = false; });
                                            return;
                                        }
                                        baris.remove();
                                        ndtRenumber();
                                    };

                                    document.addEventListener('change', function (e) {
                                        var t = e.target;
                                        if (!t.classList || !t.classList.contains('lp-ndt-chk')) return;
                                        if (t.checked) {
                                            document.querySelectorAll('input.lp-ndt-chk[name="' + CSS.escape(t.name) + '"]').forEach(function (chk) {
                                                if (chk !== t) chk.checked = false;
                                            });
                                        }
                                    });
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if ($adaPjn): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">V. PENGUJIAN</div>

                                <div class="d-flex justify-content-end mb-2">
                                    <button type="button" class="btn-secondary-custom" style="font-size:0.75rem;"
                                        onclick="pjnTambahBaris()">
                                        <i class="bi bi-plus-lg"></i> Tambah Baris
                                    </button>
                                </div>

                                <?php
                                $nilaiPjn = $_POST['pjn'] ?? [];
                                if (empty($nilaiPjn)) {
                                    // Contoh awal mengikuti tabel di gambar; hapus/ubah sesuai kebutuhan
                                    $nilaiPjn = [
                                        ['fungsi' => 'Travelling', 'tinggi_angkat' => '', 'kecepatan' => '', 'gerakan' => "Maju\nMundur", 'beban' => 'Tanpa Beban', 'hasil' => 'Baik', 'ket_mode' => 'manual', 'ket_manual' => 'Brake ok', 'ukur_akhir' => ''],
                                        ['fungsi' => 'Manuver', 'tinggi_angkat' => '', 'kecepatan' => '', 'gerakan' => "Kanan\nKiri", 'beban' => 'Tanpa Beban', 'hasil' => 'Baik', 'ket_mode' => 'manual', 'ket_manual' => 'Tidak ada kelainan', 'ukur_akhir' => ''],
                                        ['fungsi' => 'Lengan (Boom)', 'tinggi_angkat' => '', 'kecepatan' => '', 'gerakan' => "Naik\nTurun", 'beban' => 'Tanpa Beban', 'hasil' => 'Baik', 'ket_mode' => 'manual', 'ket_manual' => 'Tidak ada kelainan', 'ukur_akhir' => ''],
                                        ['fungsi' => 'Bak (Bucket)', 'tinggi_angkat' => '', 'kecepatan' => '', 'gerakan' => "Naik\nTurun", 'beban' => 'Tanpa Beban', 'hasil' => 'Baik', 'ket_mode' => 'manual', 'ket_manual' => 'Tidak ada kelainan', 'ukur_akhir' => ''],
                                        ['fungsi' => "Gerakan\n(Loading dan Unloading)", 'tinggi_angkat' => '', 'kecepatan' => '', 'gerakan' => "Travelling\nNaik Turun", 'beban' => 'Tanpa Beban', 'hasil' => 'Baik', 'ket_mode' => 'manual', 'ket_manual' => 'Tidak ada kelainan', 'ukur_akhir' => ''],
                                        ['fungsi' => "Gerakan\n(Loading dan Unloading)", 'tinggi_angkat' => '711 mm', 'kecepatan' => 'Statis', 'gerakan' => 'Statis', 'beban' => "Pasir\n3 m³", 'hasil' => 'Baik', 'ket_mode' => 'hitung', 'ket_manual' => '', 'ukur_akhir' => '708'],
                                    ];
                                }
                                ?>
                                <div class="table-responsive-custom">
                                    <table class="table-custom" id="pjn-tabel">
                                        <thead>
                                            <tr>
                                                <th style="width:36px; text-align:center;">No</th>
                                                <th style="width:160px;">Fungsi</th>
                                                <th style="width:110px;">Tinggi Angkat</th>
                                                <th style="width:110px;">Kecepatan</th>
                                                <th style="width:140px;">Gerakan</th>
                                                <th style="width:140px;">Beban</th>
                                                <th style="width:80px;">Hasil</th>
                                                <th style="width:240px;">Ket</th>
                                                <th style="width:40px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="pjn-tabel-body">
                                            <?php foreach (array_values($nilaiPjn) as $idx => $baris): ?>
                                                <?php $ketMode = ($baris['ket_mode'] ?? 'manual') === 'hitung' ? 'hitung' : 'manual'; ?>
                                                <tr class="pjn-baris">
                                                    <td class="pjn-nomor" style="text-align:center;"><?= $idx + 1 ?></td>
                                                    <td><textarea name="pjn[<?= $idx ?>][fungsi]" rows="1"
                                                            class="form-control-custom lp-textarea-ket"
                                                            oninput="lpAutoGrowTextarea(this)"><?= e($baris['fungsi'] ?? '') ?></textarea>
                                                    </td>
                                                    <td><input type="text" name="pjn[<?= $idx ?>][tinggi_angkat]"
                                                            class="form-control-custom text-xs"
                                                            value="<?= e($baris['tinggi_angkat'] ?? '') ?>"
                                                            placeholder="Contoh: 711 mm"></td>
                                                    <td><input type="text" name="pjn[<?= $idx ?>][kecepatan]"
                                                            class="form-control-custom text-xs"
                                                            value="<?= e($baris['kecepatan'] ?? '') ?>"
                                                            placeholder="Statis/Dinamis"></td>
                                                    <td><textarea name="pjn[<?= $idx ?>][gerakan]" rows="1"
                                                            class="form-control-custom lp-textarea-ket"
                                                            oninput="lpAutoGrowTextarea(this)"
                                                            placeholder="Enter = baris baru"><?= e($baris['gerakan'] ?? '') ?></textarea>
                                                    </td>
                                                    <td><textarea name="pjn[<?= $idx ?>][beban]" rows="1"
                                                            class="form-control-custom lp-textarea-ket"
                                                            oninput="lpAutoGrowTextarea(this)"><?= e($baris['beban'] ?? '') ?></textarea>
                                                    </td>
                                                    <td><input type="text" name="pjn[<?= $idx ?>][hasil]"
                                                            class="form-control-custom text-xs"
                                                            value="<?= e($baris['hasil'] ?? 'Baik') ?>"></td>
                                                    <td>
                                                        <div class="d-flex flex-column gap-1">
                                                            <select name="pjn[<?= $idx ?>][ket_mode]" class="select-custom text-xs"
                                                                onchange="pjnToggleKet(this)">
                                                                <option value="manual" <?= $ketMode === 'manual' ? 'selected' : '' ?>>
                                                                    Manual</option>
                                                                <option value="hitung" <?= $ketMode === 'hitung' ? 'selected' : '' ?>>
                                                                    Hitung Penurunan</option>
                                                            </select>
                                                            <textarea name="pjn[<?= $idx ?>][ket_manual]" rows="1"
                                                                class="form-control-custom lp-textarea-ket pjn-ket-manual"
                                                                style="<?= $ketMode === 'hitung' ? 'display:none;' : '' ?>"
                                                                oninput="lpAutoGrowTextarea(this)"><?= e($baris['ket_manual'] ?? '') ?></textarea>
                                                            <div class="pjn-ket-hitung"
                                                                style="<?= $ketMode === 'hitung' ? '' : 'display:none;' ?>">
                                                                <label class="text-secondary text-xs mb-1 d-block">Ukur Akhir
                                                                    (mm)</label>
                                                                <input type="text" name="pjn[<?= $idx ?>][ukur_akhir]"
                                                                    class="form-control-custom text-xs"
                                                                    value="<?= e($baris['ukur_akhir'] ?? '') ?>"
                                                                    placeholder="Contoh: 708">
                                                                <small class="text-secondary text-xs d-block mt-1">Hasil: "Tidak
                                                                    Terjadi Penurunan" + "Tinggi Angkat-Ukur Akhir=Selisih".</small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <button type="button" class="btn btn-outline-danger btn-sm py-1"
                                                            style="font-size:0.7rem;" title="Hapus baris"
                                                            onclick="pjnHapusBaris(this)">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Kolom Fungsi, Gerakan, Beban, dan Ket bisa multi-baris (tekan Enter). Kolom kosong tercetak
                                    "-" di Word.
                                    Pilih "Hitung Penurunan" pada Ket untuk baris statis: angka pertama diambil dari Tinggi
                                    Angkat, angka kedua dari Ukur Akhir.
                                </small>
                            </div>
                            <script>
                                (function () {
                                    if (typeof window.lpAutoGrowTextarea !== 'function') {
                                        window.lpAutoGrowTextarea = function (el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; };
                                    }
                                    document.querySelectorAll('#pjn-tabel-body textarea').forEach(window.lpAutoGrowTextarea);

                                    function renumber() {
                                        document.querySelectorAll('#pjn-tabel-body .pjn-baris').forEach(function (tr, idx) {
                                            tr.querySelector('.pjn-nomor').textContent = idx + 1;
                                            tr.querySelectorAll('[name^="pjn["]').forEach(function (el) {
                                                el.name = el.name.replace(/^pjn\[\d+\]/, 'pjn[' + idx + ']');
                                            });
                                        });
                                    }
                                    window.pjnToggleKet = function (sel) {
                                        var td = sel.closest('td');
                                        var hitung = sel.value === 'hitung';
                                        td.querySelector('.pjn-ket-manual').style.display = hitung ? 'none' : '';
                                        td.querySelector('.pjn-ket-hitung').style.display = hitung ? '' : 'none';
                                    };
                                    window.pjnTambahBaris = function () {
                                        var tbody = document.getElementById('pjn-tabel-body');
                                        var semua = tbody.querySelectorAll('.pjn-baris');
                                        var acuan = semua[semua.length - 1];
                                        if (!acuan) return;
                                        var baru = acuan.cloneNode(true);
                                        baru.querySelectorAll('input[type=text]').forEach(function (el) { el.value = ''; });
                                        baru.querySelectorAll('textarea').forEach(function (el) {
                                            el.value = ''; el.removeAttribute('style'); window.lpAutoGrowTextarea(el);
                                        });
                                        baru.querySelector('[name$="[hasil]"]').value = 'Baik';
                                        var sel = baru.querySelector('select');
                                        sel.value = 'manual';
                                        pjnToggleKet(sel);
                                        tbody.appendChild(baru);
                                        renumber();
                                    };
                                    window.pjnHapusBaris = function (btn) {
                                        var tbody = document.getElementById('pjn-tabel-body');
                                        var baris = btn.closest('tr.pjn-baris');
                                        if (tbody.querySelectorAll('tr.pjn-baris').length <= 1) {
                                            baris.querySelectorAll('input[type=text], textarea').forEach(function (el) { el.value = ''; });
                                            return;
                                        }
                                        baris.remove();
                                        renumber();
                                    };
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if ($adaPuj): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">V. PENGUJIAN</div>

                                <div class="d-flex justify-content-end mb-2">
                                    <button type="button" class="btn-secondary-custom" style="font-size:0.75rem;"
                                        onclick="pujTambahBaris()">
                                        <i class="bi bi-plus-lg"></i> Tambah Baris
                                    </button>
                                </div>

                                <?php
                                $nilaiPuj = $_POST['puj'] ?? [];
                                if (empty($nilaiPuj)) {
                                    $nilaiPuj = [
                                        [
                                            'tinggi_angkat' => '',
                                            'beban' => '',
                                            'kecepatan' => 'Dinamis',
                                            'gerakan' => [''],
                                            'hasil' => 'Baik',
                                            'ket_mode' => 'manual',
                                            'ket_manual' => 'Tidak Ada Kelainan',
                                            'ukur_akhir' => '',
                                        ]
                                    ];
                                }
                                ?>
                                <div class="table-responsive-custom">
                                    <table class="table-custom" id="puj-tabel">
                                        <thead>
                                            <tr>
                                                <th style="width:100px;">Tinggi Angkat</th>
                                                <th style="width:150px;">Beban Uji</th>
                                                <th style="width:100px;">Kecepatan</th>
                                                <th>Gerakan</th>
                                                <th style="width:90px;">Hasil</th>
                                                <th style="width:260px;">Ket</th>
                                                <th style="width:40px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="puj-tabel-body">
                                            <?php foreach ($nilaiPuj as $idx => $baris): ?>
                                                <?php
                                                $gerakanBaris = (array) ($baris['gerakan'] ?? ['']);
                                                if (empty($gerakanBaris))
                                                    $gerakanBaris = [''];
                                                $ketMode = ($baris['ket_mode'] ?? 'manual') === 'hitung' ? 'hitung' : 'manual';
                                                ?>
                                                <tr class="puj-baris" data-idx="<?= (int) $idx ?>">
                                                    <td>
                                                        <input type="text" name="puj[<?= (int) $idx ?>][tinggi_angkat]"
                                                            class="form-control-custom text-xs"
                                                            value="<?= e($baris['tinggi_angkat'] ?? '') ?>"
                                                            placeholder="Contoh: 989 mm">
                                                    </td>
                                                    <td>
                                                        <input type="text" name="puj[<?= (int) $idx ?>][beban]"
                                                            class="form-control-custom text-xs"
                                                            value="<?= e($baris['beban'] ?? '') ?>"
                                                            placeholder="Contoh: Tanpa Beban">
                                                    </td>
                                                    <td>
                                                        <input type="text" name="puj[<?= (int) $idx ?>][kecepatan]"
                                                            class="form-control-custom text-xs"
                                                            value="<?= e($baris['kecepatan'] ?? '') ?>"
                                                            placeholder="Dinamis/Statis">
                                                    </td>
                                                    <td>
                                                        <div class="puj-gerakan-list d-flex flex-column gap-1">
                                                            <?php foreach ($gerakanBaris as $teksGerakan): ?>
                                                                <div class="d-flex align-items-center gap-1 puj-gerakan-item">
                                                                    <span class="puj-gerakan-huruf text-secondary text-xs"
                                                                        style="width:16px;"></span>
                                                                    <input type="text" name="puj[<?= (int) $idx ?>][gerakan][]"
                                                                        class="form-control-custom text-xs"
                                                                        value="<?= e($teksGerakan) ?>"
                                                                        placeholder="Contoh: Maju Mundur">
                                                                    <button type="button"
                                                                        class="btn btn-outline-danger btn-sm py-0 px-1"
                                                                        style="font-size:0.65rem;" title="Hapus baris gerakan"
                                                                        onclick="pujHapusGerakan(this)">
                                                                        <i class="bi bi-x"></i>
                                                                    </button>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <button type="button"
                                                            class="btn btn-outline-secondary btn-sm py-0 px-2 mt-1"
                                                            style="font-size:0.7rem;" onclick="pujTambahGerakan(this)">
                                                            <i class="bi bi-plus-lg"></i> Tambah Gerakan
                                                        </button>
                                                    </td>
                                                    <td>
                                                        <input type="text" name="puj[<?= (int) $idx ?>][hasil]"
                                                            class="form-control-custom text-xs"
                                                            value="<?= e($baris['hasil'] ?? '') ?>" placeholder="Baik">
                                                    </td>
                                                    <td>
                                                        <div class="d-flex flex-column gap-1">
                                                            <select name="puj[<?= (int) $idx ?>][ket_mode]"
                                                                class="select-custom text-xs puj-ket-mode"
                                                                onchange="pujToggleKetMode(this)">
                                                                <option value="manual" <?= $ketMode === 'manual' ? 'selected' : '' ?>>
                                                                    Manual</option>
                                                                <option value="hitung" <?= $ketMode === 'hitung' ? 'selected' : '' ?>>
                                                                    Hitung Penurunan (mm)</option>
                                                            </select>

                                                            <textarea name="puj[<?= (int) $idx ?>][ket_manual]" rows="1"
                                                                class="form-control-custom lp-textarea-ket puj-ket-manual"
                                                                style="<?= $ketMode === 'hitung' ? 'display:none;' : '' ?>"
                                                                oninput="lpAutoGrowTextarea(this)"><?= e($baris['ket_manual'] ?? '') ?></textarea>

                                                            <div class="puj-ket-hitung"
                                                                style="<?= $ketMode === 'hitung' ? '' : 'display:none;' ?>">
                                                                <label class="text-secondary text-xs mb-1 d-block">Ukur Akhir
                                                                    (mm)</label>
                                                                <input type="text" name="puj[<?= (int) $idx ?>][ukur_akhir]"
                                                                    class="form-control-custom text-xs"
                                                                    value="<?= e($baris['ukur_akhir'] ?? '') ?>"
                                                                    placeholder="Contoh: 989">
                                                                <small class="text-secondary text-xs d-block mt-1">
                                                                    Otomatis: "Tidak ada penurunan pengukuran, Tinggi Angkat – Ukur
                                                                    Akhir = ... mm".
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <button type="button" class="btn btn-outline-danger btn-sm py-1"
                                                            style="font-size:0.7rem;" title="Hapus baris"
                                                            onclick="pujHapusBaris(this)">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Kolom "Gerakan" bisa lebih dari satu baris (otomatis diberi huruf a, b, c, ...);
                                    kalau hanya satu baris, tidak diberi huruf. Pilih "Hitung Penurunan (mm)" pada
                                    kolom Ket untuk baris statis (Tinggi Angkat − Ukur Akhir dihitung otomatis).
                                </small>
                            </div>
                            <script>
                                (function () {
                                    function pujHurufUlang(list) {
                                        var items = list.querySelectorAll('.puj-gerakan-item');
                                        items.forEach(function (item, i) {
                                            item.querySelector('.puj-gerakan-huruf').textContent =
                                                items.length > 1 ? String.fromCharCode(97 + i) + '.' : '';
                                        });
                                    }
                                    document.querySelectorAll('.puj-gerakan-list').forEach(pujHurufUlang);

                                    window.pujTambahGerakan = function (btn) {
                                        var list = btn.previousElementSibling;
                                        var contoh = list.querySelector('.puj-gerakan-item');
                                        var baru = contoh.cloneNode(true);
                                        baru.querySelector('input').value = '';
                                        list.appendChild(baru);
                                        pujHurufUlang(list);
                                    };
                                    window.pujHapusGerakan = function (btn) {
                                        var list = btn.closest('.puj-gerakan-list');
                                        var item = btn.closest('.puj-gerakan-item');
                                        if (list.querySelectorAll('.puj-gerakan-item').length <= 1) {
                                            item.querySelector('input').value = '';
                                            return;
                                        }
                                        item.remove();
                                        pujHurufUlang(list);
                                    };

                                    window.pujToggleKetMode = function (sel) {
                                        var td = sel.closest('td');
                                        var manual = td.querySelector('.puj-ket-manual');
                                        var hitung = td.querySelector('.puj-ket-hitung');
                                        if (sel.value === 'hitung') { manual.style.display = 'none'; hitung.style.display = ''; }
                                        else { manual.style.display = ''; hitung.style.display = 'none'; }
                                    };

                                    function pujRenumber() {
                                        document.querySelectorAll('#puj-tabel-body .puj-baris').forEach(function (tr, idx) {
                                            tr.querySelectorAll('[name^="puj["]').forEach(function (el) {
                                                el.name = el.name.replace(/^puj\[\d+\]/, 'puj[' + idx + ']');
                                            });
                                        });
                                    }

                                    window.pujTambahBaris = function () {
                                        var tbody = document.getElementById('puj-tabel-body');
                                        var semuaBaris = tbody.querySelectorAll('.puj-baris');
                                        var acuan = semuaBaris[semuaBaris.length - 1];
                                        if (!acuan) return;
                                        var baru = acuan.cloneNode(true);

                                        baru.querySelectorAll('input[type=text]').forEach(function (el) { el.value = ''; });
                                        baru.querySelectorAll('textarea').forEach(function (el) {
                                            el.value = '';
                                            el.removeAttribute('style');
                                            lpAutoGrowTextarea(el);
                                        });
                                        var listGerakan = baru.querySelector('.puj-gerakan-list');
                                        var itemGerakan = listGerakan.querySelectorAll('.puj-gerakan-item');
                                        for (var i = 1; i < itemGerakan.length; i++) itemGerakan[i].remove();
                                        pujHurufUlang(listGerakan);

                                        var sel = baru.querySelector('.puj-ket-mode');
                                        sel.value = 'manual';
                                        pujToggleKetMode(sel);

                                        tbody.appendChild(baru);
                                        pujRenumber();
                                    };

                                    window.pujHapusBaris = function (btn) {
                                        var tbody = document.getElementById('puj-tabel-body');
                                        var baris = btn.closest('tr.puj-baris');
                                        if (tbody.querySelectorAll('tr.puj-baris').length <= 1) {
                                            baris.querySelectorAll('input[type=text], textarea').forEach(function (el) { el.value = ''; });
                                            return;
                                        }
                                        baris.remove();
                                        pujRenumber();
                                    };
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if (!empty($fields_kmp_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">VI. Analisis</div>

                                <div class="lp-subsection">
                                    <div class="lp-subsection-title">A. Analisa Komponen</div>
                                    <div class="lp-subsection-body">
                                        <small class="text-secondary text-xs">
                                            Analisa pada sistem hidrolik berdasarkan diameter lift silinder dan tekanan pompa.
                                        </small>

                                        <div class="text-xs fw-semibold mt-1">Diketahui :</div>
                                        <div class="table-responsive-custom">
                                            <table class="table-custom" style="margin:0; max-width:620px;">
                                                <tbody>
                                                    <tr>
                                                        <td style="width:250px;">Kapasitas Bucket / SWL</td>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <input type="text" name="dinamis[kmp_kapasitas_bucket]"
                                                                    class="form-control-custom text-xs" style="max-width:160px;"
                                                                    placeholder="Contoh: 3"
                                                                    value="<?= e($nilai_dinamis_lp['kmp_kapasitas_bucket'] ?? '') ?>">
                                                                <span class="text-xs">m³</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td>D Torak</td>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <input type="text" name="dinamis[kmp_diameter_torak]"
                                                                    class="form-control-custom text-xs" style="max-width:160px;"
                                                                    placeholder="Contoh: 5.1"
                                                                    value="<?= e($nilai_dinamis_lp['kmp_diameter_torak'] ?? '') ?>">
                                                                <span class="text-xs">cm</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td>Working Pressure (P)</td>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                                <input type="text" name="dinamis[kmp_tekanan_mpa]"
                                                                    class="form-control-custom text-xs" style="max-width:160px;"
                                                                    placeholder="Contoh: 18"
                                                                    value="<?= e($nilai_dinamis_lp['kmp_tekanan_mpa'] ?? '') ?>">
                                                                <span class="text-xs">MPa</span>
                                                                <span class="text-secondary text-xs">= <span
                                                                        id="kmp-tampil-tekanan">-</span></span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td>Jumlah Torak (n)</td>
                                                        <td><?= (int) LP_KMP_JUMLAH_TORAK ?> <small
                                                                class="text-secondary">(tetap)</small></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Massa jenis batu split (ρ)</td>
                                                        <td><?= (int) LP_KMP_MASSA_JENIS ?> kg/m³ <small
                                                                class="text-secondary">(tetap)</small></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="text-xs fw-semibold mt-2">Ditanya : Kekuatan Angkat</div>
                                        <div id="kmp-preview" class="text-xs text-secondary" style="white-space:pre-line;">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <script>
                                (function () {
                                    var form = document.getElementById('form-buat-laporan');
                                    var box = document.getElementById('kmp-preview');
                                    if (!form || !box) return;

                                    var N = <?= (int) LP_KMP_JUMLAH_TORAK ?>;
                                    var RHO = <?= (int) LP_KMP_MASSA_JENIS ?>;
                                    var MPA = <?= json_encode(LP_KMP_MPA_KE_KGCM2) ?>;
                                    var PI4 = <?= json_encode(LP_KMP_PI_PER_4) ?>;

                                    function v(n) { var el = document.querySelector('[name="' + n + '"]'); return el ? el.value : ''; }
                                    function des(s) { s = String(s).replace(/[^\d.,]/g, '').replace(',', '.'); var x = parseFloat(s); return isNaN(x) ? null : x; }
                                    function pot(x, d) { var f = Math.pow(10, d); return Math.floor(x * f + 1e-9) / f; }

                                    function hitung() {
                                        var vol = des(v('dinamis[kmp_kapasitas_bucket]'));
                                        var mpa = des(v('dinamis[kmp_tekanan_mpa]'));
                                        var dia = des(v('dinamis[kmp_diameter_torak]'));
                                        var p = (mpa > 0) ? pot(mpa * MPA, 2) : null;

                                        document.getElementById('kmp-tampil-tekanan').textContent =
                                            p !== null ? (p.toFixed(2) + ' kg/cm²') : '-';
                                        var baris = [], swl = null, q = null;
                                        if (vol > 0) {
                                            swl = vol * RHO;
                                            baris.push('SWL = ' + vol + ' m³ = v x ρ = ' + vol + ' x ' + RHO + ' = ' + swl + ' kg = ' + pot(swl / 1000, 1).toFixed(1) + ' ton');
                                        }
                                        if (dia > 0) {
                                            var a = pot(PI4 * dia * dia, 2);
                                            baris.push('A = 0.785 x ' + dia + '² = ' + a.toFixed(2) + ' cm²');
                                            if (p !== null) {
                                                q = pot(a * p * N, 0);
                                                baris.push('Q = ' + a.toFixed(2) + ' x ' + p.toFixed(2) + ' x ' + N + ' = ' + q + ' kg = ' + pot(q / 1000, 1).toFixed(1) + ' ton');
                                            }
                                        }
                                        if (swl !== null && q !== null) {
                                            var sim = swl < q ? '<' : (swl > q ? '>' : '=');
                                            baris.push('Sehingga Kekuatan SWL ' + sim + ' Kekuatan Angkat, ' + pot(swl / 1000, 1).toFixed(1) + ' ' + sim + ' ' + pot(q / 1000, 1).toFixed(1) + ', maka ' + (swl <= q ? 'ACC' : 'Belum ACC'));
                                        }
                                        box.textContent = baris.length ? 'Pratinjau:\n' + baris.join('\n') : '';
                                    }
                                    form.addEventListener('input', hitung);
                                    hitung();
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if (!empty($fields_akm_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">VI. ANALISIS</div>

                                <div class="lp-subsection">
                                    <div class="lp-subsection-title">A. Analisa Komponen</div>
                                    <div class="lp-subsection-body">
                                        <small class="text-secondary text-xs">
                                            Analisa pada sistem hidrolik berdasarkan diameter lift silinder dan tekanan pompa.
                                        </small>

                                        <div class="text-xs fw-semibold mt-1">Diketahui :</div>
                                        <div class="table-responsive-custom">
                                            <table class="table-custom" style="margin:0; max-width:620px;">
                                                <tbody>
                                                    <?php foreach ($fields_akm_lp as $f): ?>
                                                        <tr>
                                                            <td><?= e($f['label']) ?></td>
                                                            <td>
                                                                <input type="text" name="dinamis[<?= e($f['field']) ?>]"
                                                                    class="form-control-custom text-xs" style="max-width:200px;"
                                                                    placeholder="<?= e(LP_PLACEHOLDER_FIELD[$f['field']] ?? '') ?>"
                                                                    value="<?= e($nilai_dinamis_lp[$f['field']] ?? '') ?>">
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="text-xs fw-semibold mt-2">Ditanya : Kekuatan Angkat</div>
                                        <div id="akm-preview" class="text-xs text-secondary" style="white-space:pre-line;">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <script>
                                (function () {
                                    var form = document.getElementById('form-buat-laporan');
                                    var box = document.getElementById('akm-preview');
                                    if (!form || !box) return;

                                    var PI4 = <?= json_encode(LP_KMP_PI_PER_4) ?>;

                                    function v(n) { var el = document.querySelector('[name="' + n + '"]'); return el ? el.value : ''; }
                                    function des(s) { s = String(s).replace(/[^\d.,]/g, '').replace(',', '.'); var x = parseFloat(s); return isNaN(x) ? null : x; }
                                    function pot(x, d) { var f = Math.pow(10, d); return Math.floor(x * f + 1e-9) / f; }

                                    function hitung() {
                                        var kap = des(v('dinamis[akm_kapasitas_spesifikasi]'));
                                        var dia = des(v('dinamis[akm_diameter_torak]'));
                                        var p = des(v('dinamis[akm_tekanan]'));
                                        var n = des(v('dinamis[akm_jumlah_torak]')); if (!(n > 0)) n = 1;
                                        var rho = des(v('dinamis[akm_massa_jenis]')); if (!(rho > 0)) rho = 1800;

                                        var baris = [], kapKg = null, q = null, a = null;
                                        if (kap > 0) {
                                            kapKg = kap * rho;
                                            baris.push('Kapasitas = ' + kap + ' m³ = v x ρ = ' + kap + ' x ' + rho + ' = ' + kapKg + ' kg = ' + pot(kapKg / 1000, 2).toFixed(2) + ' ton');
                                        }
                                        if (dia > 0) {
                                            a = pot(PI4 * dia * dia, 2);
                                            baris.push('A = 0.785 x ' + dia + '² = ' + a.toFixed(2) + ' cm²');
                                            if (p > 0) {
                                                q = pot(a * p * n, 0);
                                                baris.push('Q = ' + a.toFixed(2) + ' x ' + p + ' x ' + n + ' = ' + q + ' kg = ' + pot(q / 1000, 3).toFixed(3) + ' ton');
                                            }
                                        }
                                        if (kapKg !== null && q !== null) {
                                            var sim = kapKg < q ? '<' : (kapKg > q ? '>' : '=');
                                            baris.push('Sehingga Kekuatan SWL ' + pot(kapKg / 1000, 2).toFixed(2) + ' ' + sim + ' Kekuatan Angkat, ' + sim + ' ' + pot(q / 1000, 3).toFixed(3) + ', maka ' + (kapKg <= q ? 'ACC' : 'Belum ACC'));
                                        }
                                        box.textContent = baris.length ? 'Pratinjau:\n' + baris.join('\n') : '';
                                    }
                                    form.addEventListener('input', hitung);
                                    hitung();
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if (!empty($fields_ck_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">III. Data Checklist Pemeriksaan</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" style="width:36px; vertical-align:middle; text-align:center;">No
                                                </th>
                                                <th rowspan="2" style="vertical-align:middle; text-align:center;">Komponen</th>
                                                <th colspan="2" style="text-align:center;">Kondisi</th>
                                                <th rowspan="2" style="width:260px; vertical-align:middle; text-align:center;">
                                                    Keterangan</th>
                                            </tr>
                                            <tr>
                                                <th style="width:60px; text-align:center;">Baik</th>
                                                <th style="width:60px; text-align:center;">Buruk</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_ck_lp as $grup): ?>
                                                <tr>
                                                    <td colspan="5" style="background:#eef2ff; font-weight:700; color:#4338ca;">
                                                        <?= e($grup['kode']) ?>. <?= e(mb_strtoupper($grup['judul'])) ?>
                                                    </td>
                                                </tr>
                                                <?php foreach ($grup['items'] as $it): ?>
                                                    <?php $stCk = $nilai_ck_status_lp[$it['key']] ?? ''; ?>
                                                    <tr>
                                                        <td style="text-align:center;"><?= (int) $it['no'] ?></td>
                                                        <td><?= e($it['label']) ?></td>
                                                        <?php foreach (['baik', 'buruk'] as $opsi): ?>
                                                            <td style="text-align:center;">
                                                                <input type="checkbox" class="lp-ck-chk2"
                                                                    name="ck_status[<?= e($it['key']) ?>]" value="<?= $opsi ?>"
                                                                    <?= $stCk === $opsi ? 'checked' : '' ?>>
                                                            </td>
                                                        <?php endforeach; ?>
                                                        <td>
                                                            <?php if (!empty($it['field_ket'])): ?>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="ck_ket[<?= e($it['key']) ?>]" rows="1"
                                                                    oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'"><?= e($nilai_ck_ket_lp[$it['key']] ?? '') ?></textarea>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Klik "Baik" atau "Buruk" untuk mencentang salah satu. Klik lagi pada kotak yang sudah
                                    tercentang
                                    untuk membatalkan pilihan (kosong). Jika tidak ada yang dipilih, kedua kolom otomatis
                                    tercetak "-" di Word.
                                </small>
                            </div>
                            <script>
                                (function () {
                                    document.addEventListener('change', function (e) {
                                        var t = e.target;
                                        if (!t.classList || !t.classList.contains('lp-ck-chk2')) return;
                                        if (t.checked) {
                                            document.querySelectorAll('input.lp-ck-chk2[name="' + CSS.escape(t.name) + '"]').forEach(function (chk) {
                                                if (chk !== t) chk.checked = false;
                                            });
                                        }
                                    });
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if (!empty($fields_ukur_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">A. Pengujian dan Pengukuran</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th style="width:36px;">No</th>
                                                <th>Komponen yang Diuji</th>
                                                <th style="width:200px;">Hasil</th>
                                                <th style="width:260px;">Keterangan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_ukur_lp as $u): ?>
                                                <?php if (!empty($u['sub'])): ?>
                                                    <?php foreach ($u['sub'] as $i => $s): ?>
                                                        <tr>
                                                            <?php if ($i === 0): ?>
                                                                <td rowspan="<?= count($u['sub']) ?>"><?= (int) $u['no'] ?>.</td>
                                                            <?php endif; ?>
                                                            <td><?= e($s['label']) ?></td>
                                                            <td>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="dinamis[<?= e($s['field']) ?>]" rows="1"
                                                                    oninput="lpAutoGrowTextarea(this)"><?= e($nilai_ukur_lp[$s['field']] ?? '') ?></textarea>
                                                            </td>
                                                            <?php if ($i === 0): ?>
                                                                <td rowspan="<?= count($u['sub']) ?>">
                                                                    <?php if ($u['field_ket']): ?>
                                                                        <textarea class="form-control-custom lp-textarea-ket"
                                                                            name="dinamis[<?= e($u['field_ket']) ?>]" rows="1"
                                                                            oninput="lpAutoGrowTextarea(this)"><?= e($nilai_ukur_lp[$u['field_ket']] ?? '') ?></textarea>
                                                                    <?php endif; ?>
                                                                </td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td><?= (int) $u['no'] ?>.</td>
                                                        <td><?= e($u['label']) ?></td>
                                                        <td>
                                                            <textarea class="form-control-custom lp-textarea-ket"
                                                                name="dinamis[<?= e($u['field_nilai']) ?>]" rows="1"
                                                                oninput="lpAutoGrowTextarea(this)"><?= e($nilai_ukur_lp[$u['field_nilai']] ?? '') ?></textarea>
                                                        </td>
                                                        <td>
                                                            <?php if ($u['field_ket']): ?>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="dinamis[<?= e($u['field_ket']) ?>]" rows="1"
                                                                    oninput="lpAutoGrowTextarea(this)"><?= e($nilai_ukur_lp[$u['field_ket']] ?? '') ?></textarea>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fields_sfd_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">B. Pengukuran dan Pengujian Safety Device</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th style="width:36px;">No</th>
                                                <th>Komponen yang Diuji</th>
                                                <th style="width:200px;">Hasil</th>
                                                <th style="width:260px;">Keterangan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_sfd_lp as $u): ?>
                                                <?php if (!empty($u['sub'])): ?>
                                                    <?php foreach ($u['sub'] as $i => $s): ?>
                                                        <tr>
                                                            <?php if ($i === 0): ?>
                                                                <td rowspan="<?= count($u['sub']) ?>"><?= (int) $u['no'] ?>.</td>
                                                            <?php endif; ?>
                                                            <td><?= e($s['label']) ?></td>
                                                            <td>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="dinamis[<?= e($s['field']) ?>]" rows="1"
                                                                    oninput="lpAutoGrowTextarea(this)"><?= e($nilai_sfd_lp[$s['field']] ?? '') ?></textarea>
                                                            </td>
                                                            <?php if ($i === 0): ?>
                                                                <td rowspan="<?= count($u['sub']) ?>">
                                                                    <?php if ($u['field_ket']): ?>
                                                                        <textarea class="form-control-custom lp-textarea-ket"
                                                                            name="dinamis[<?= e($u['field_ket']) ?>]" rows="1"
                                                                            oninput="lpAutoGrowTextarea(this)"><?= e($nilai_sfd_lp[$u['field_ket']] ?? '') ?></textarea>
                                                                    <?php endif; ?>
                                                                </td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td><?= (int) $u['no'] ?>.</td>
                                                        <td><?= e($u['label']) ?></td>
                                                        <td>
                                                            <textarea class="form-control-custom lp-textarea-ket"
                                                                name="dinamis[<?= e($u['field_nilai']) ?>]" rows="1"
                                                                oninput="lpAutoGrowTextarea(this)"><?= e($nilai_sfd_lp[$u['field_nilai']] ?? '') ?></textarea>
                                                        </td>
                                                        <td>
                                                            <?php if ($u['field_ket']): ?>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="dinamis[<?= e($u['field_ket']) ?>]" rows="1"
                                                                    oninput="lpAutoGrowTextarea(this)"><?= e($nilai_sfd_lp[$u['field_ket']] ?? '') ?></textarea>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fields_tegangan_lp) || $adaHasilUkur): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">C. Pengukuran Tegangan</div>

                                <?php if (!empty($fields_tegangan_lp)): ?>
                                    <div class="lp-subsection">
                                        <div class="lp-subsection-title">Pengukuran Panel Listrik <span
                                                class="fw-normal fst-italic">(Electrical Panel Test)</span></div>
                                        <div class="lp-subsection-body">
                                            <div class="d-flex align-items-center gap-2">
                                                <label class="form-label fw-semibold mb-0 text-xs lp-field-label">Waktu Pelaksanaan
                                                    Test</label>
                                                <input type="time" name="dinamis[teg_jam]" class="form-control-custom text-xs"
                                                    style="max-width:140px;" value="<?= e($nilai_dinamis_lp['teg_jam'] ?? '') ?>">
                                                <small class="text-secondary text-xs">Tanggal mengikuti field Tanggal Pemeriksaan di
                                                    atas.</small>
                                            </div>

                                            <div class="row g-3 mt-1">
                                                <?php $tegKolom = array_chunk($fields_tegangan_lp, 2); ?>
                                                <?php foreach ($tegKolom as $kolom): ?>
                                                    <div class="col-md-4">
                                                        <?php foreach ($kolom as $it): ?>
                                                            <?php $stTeg = $nilai_tegangan_status_lp[$it['key']] ?? 'tidak'; ?>
                                                            <div class="mb-3 p-2"
                                                                style="border:1px solid #e2e8f0; border-radius:6px; background:#f8fafc;">
                                                                <div class="text-xs fw-semibold mb-1"><?= e($it['label']) ?></div>
                                                                <div class="d-flex align-items-center gap-3">
                                                                    <?php foreach (['ya' => '√', 'tidak' => 'Kosong', 'na' => '-'] as $opsi => $lbl): ?>
                                                                        <label class="d-flex align-items-center gap-1 text-xs mb-0"
                                                                            style="cursor:pointer;">
                                                                            <input type="radio" name="tegangan_status[<?= e($it['key']) ?>]"
                                                                                value="<?= $opsi ?>" <?= $stTeg === $opsi ? 'checked' : '' ?>>
                                                                            <?= e($lbl) ?>
                                                                        </label>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <small class="text-secondary text-xs d-block mb-3">
                                        √ = dicentang, Kosong = tidak dicentang, "-" = tidak diuji/tidak berlaku.
                                    </small>
                                <?php endif; ?>

                                <?php if ($adaHasilUkur): ?>
                                    <div class="lp-subsection">
                                        <div class="lp-subsection-title">HASIL/RESULT</div>
                                        <div class="lp-subsection-body">
                                            <?php foreach (LP_HASIL_UKUR_GRUP as $judulGrup => $daftarField): ?>
                                                <?php
                                                $adaDiTemplate = array_filter(array_keys($daftarField), fn($k) => array_key_exists($k, $nilai_dinamis_lp));
                                                if (!$adaDiTemplate)
                                                    continue;
                                                ?>
                                                <div>
                                                    <div class="text-xs fw-semibold mb-1"><?= e($judulGrup) ?></div>
                                                    <div class="row g-2">
                                                        <?php foreach ($daftarField as $namaField => $label): ?>
                                                            <?php if (!array_key_exists($namaField, $nilai_dinamis_lp))
                                                                continue; ?>
                                                            <div class="col-6 col-md-2">
                                                                <label
                                                                    class="form-label mb-1 text-xs text-secondary"><?= e($label) ?></label>
                                                                <input type="text" name="dinamis[<?= e($namaField) ?>]"
                                                                    class="form-control-custom text-xs"
                                                                    placeholder="<?= e(LP_PLACEHOLDER_FIELD[$namaField] ?? '') ?>"
                                                                    value="<?= e($nilai_dinamis_lp[$namaField] ?? '') ?>">
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <small class="text-secondary text-xs">
                                                Kosongkan kolom yang tidak diukur, otomatis tercetak "-" di Word.
                                            </small>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fields_pondasi_lp)): ?>
                            <?php
                            $pndGrup = [
                                'Data Motor Diesel' => [
                                    'pnd_berat_mesin' => ['A. Berat Mesin (Kg)', 'Contoh: 4604'],
                                    'pnd_massa_jenis' => ['B. Massa Jenis Beton (ρ) (Kg/m³)', 'Contoh: 2400'],
                                    'pnd_koefisien' => ['C. Koefisien (C)', 'Contoh: 0.11'],
                                ],
                                'Dimensi Pondasi Riil (meter)' => [
                                    'pnd_panjang' => ['Panjang', 'Contoh: 4.611'],
                                    'pnd_lebar' => ['Lebar', 'Contoh: 2.1'],
                                    'pnd_tinggi' => ['Tinggi', 'Contoh: 0.422'],
                                ],
                            ];
                            ?>
                            <div class="mt-3">
                                <div class="lp-section-title">VI. Analisis</div>

                                <div class="lp-subsection">
                                    <div class="lp-subsection-title">A. Perhitungan Pondasi</div>
                                    <div class="lp-subsection-body">
                                        <small class="text-secondary text-xs">
                                            Nilai n (putaran) otomatis diambil dari <strong>Putaran</strong> di Data Teknis
                                            (kosong = 1500). Sisanya diisi manual.
                                        </small>

                                        <?php foreach ($pndGrup as $judulGrup => $daftar): ?>
                                            <div class="text-xs fw-semibold mt-1"><?= e($judulGrup) ?></div>
                                            <?php foreach ($daftar as $namaField => [$label, $hint]): ?>
                                                <?php if (!array_key_exists($namaField, $nilai_dinamis_lp))
                                                    continue; ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <label
                                                        class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($label) ?></label>
                                                    <input type="text" name="dinamis[<?= e($namaField) ?>]"
                                                        class="form-control-custom text-xs" style="flex:1 1 auto;"
                                                        placeholder="<?= e($hint) ?>"
                                                        value="<?= e($nilai_dinamis_lp[$namaField] ?? '') ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>

                                        <div id="pnd-preview" class="text-xs text-secondary mt-2"></div>
                                    </div>
                                </div>
                            </div>

                            <script>
                                (function () {
                                    var box = document.getElementById('pnd-preview');
                                    var form = document.getElementById('form-buat-laporan');
                                    if (!box || !form) return;
                                    function v(n) { var el = document.querySelector('[name="' + n + '"]'); return el ? el.value : ''; }
                                    function des(s) { s = String(s).replace(/[^\d.,]/g, '').replace(',', '.'); var x = parseFloat(s); return isNaN(x) ? null : x; }
                                    function bul(s) { s = String(s).replace(/[^\d.,]/g, ''); if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, ''); return des(s); }
                                    function pot(x) { return Math.floor(x * 100 + 1e-9) / 100; }
                                    function hitung() {
                                        var n = bul(v('dinamis[gen_putaran]')) || 1500;
                                        var wm = bul(v('dinamis[pnd_berat_mesin]')), rho = bul(v('dinamis[pnd_massa_jenis]'));
                                        var c = des(v('dinamis[pnd_koefisien]'));
                                        var p = des(v('dinamis[pnd_panjang]')), l = des(v('dinamis[pnd_lebar]')), t = des(v('dinamis[pnd_tinggi]'));
                                        var teks = [], izin = null, aktual = null;
                                        if (c > 0 && wm > 0) {
                                            var lbs = c * wm * Math.sqrt(n);
                                            izin = pot(lbs / 2000);
                                            teks.push('W izin = ' + pot(lbs).toFixed(2) + ' lbs = ' + izin.toFixed(2) + ' Ton (n = ' + n + ')');
                                        }
                                        if (p > 0 && l > 0 && t > 0 && rho > 0) {
                                            aktual = pot(p * l * t * rho / 1000);
                                            teks.push('W riil = ' + aktual.toFixed(2) + ' Ton');
                                        }
                                        if (izin !== null && aktual !== null) {
                                            teks.push(aktual >= izin ? 'Status: ACC' : 'Status: Belum ACC');
                                        }
                                        box.textContent = teks.length ? 'Pratinjau: ' + teks.join('  |  ') : '';
                                    }
                                    form.addEventListener('input', hitung);
                                    hitung();
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if (!empty($pengujian_aktif_lp)): ?>
                            <?php
                            $labelPengujianLp = [
                                'isolasi' => 'Tahanan Isolasi',
                                'pembumian' => 'Tahanan Pembumian',
                            ];
                            ?>
                            <div class="mt-3 mb-2">
                                <div class="lp-section-title">II.PEMERIKSAAN DAN PENGUJIAN</div>
                                <div class="d-flex flex-column gap-2">
                                    <?php foreach ($pengujian_aktif_lp as $keyPengujian): ?>
                                        <div class="d-flex align-items-start gap-2">
                                            <input type="checkbox" name="pengujian[<?= e($keyPengujian) ?>]"
                                                id="pengujian_<?= e($keyPengujian) ?>" value="1"
                                                <?= !empty($nilai_pengujian_lp[$keyPengujian]) ? 'checked' : '' ?>>
                                            <label for="pengujian_<?= e($keyPengujian) ?>" class="text-xs mb-0">
                                                <?= e($labelPengujianLp[$keyPengujian] ?? ucfirst($keyPengujian)) ?>
                                                <span class="text-secondary d-block">
                                                    Jika dicentang: "<?= e(LP_PENGUJIAN_TEKS[$keyPengujian]) ?>"
                                                </span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($adaChkBejana): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">II. Checklist Pemeriksaan</div>
                                <?php foreach (LP_CHK_BEJANA_GRUP as $judulGrup => $daftarField): ?>
                                    <?php
                                    $adaDiTemplate = array_filter(array_keys($daftarField), fn($k) => array_key_exists($k, $nilai_dinamis_lp));
                                    if (!$adaDiTemplate)
                                        continue;
                                    ?>
                                    <div class="lp-subsection">
                                        <div class="lp-subsection-title"><?= e($judulGrup) ?></div>
                                        <div class="lp-subsection-body">
                                            <?php foreach ($daftarField as $namaField => $label): ?>
                                                <?php if (!array_key_exists($namaField, $nilai_dinamis_lp))
                                                    continue; ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <label
                                                        class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($label) ?></label>
                                                    <input type="text" name="dinamis[<?= e($namaField) ?>]"
                                                        class="form-control-custom text-xs" style="flex:1 1 auto;"
                                                        value="<?= e($nilai_dinamis_lp[$namaField] ?? '') ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fields_ketel_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">III. Pemeriksaan Visual</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th rowspan="2" style="vertical-align:middle; text-align:center;">Komponen</th>
                                                <th colspan="2" style="text-align:center;">Kondisi</th>
                                                <th rowspan="2" style="width:260px; vertical-align:middle; text-align:center;">
                                                    Keterangan</th>
                                            </tr>
                                            <tr>
                                                <th style="width:70px; text-align:center;">Baik</th>
                                                <th style="width:70px; text-align:center;">Buruk</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_ketel_lp as $grup): ?>
                                                <tr>
                                                    <td colspan="4" style="background:#eef2ff; font-weight:700; color:#4338ca;">
                                                        <?= e(mb_strtoupper($grup['judul'])) ?>
                                                    </td>
                                                </tr>
                                                <?php $subSebelumnya = null; ?>
                                                <?php foreach ($grup['items'] as $it): ?>
                                                    <?php
                                                    $subSekarang = $it['sub_judul'] ?? null;
                                                    if ($subSekarang !== null && $subSekarang !== $subSebelumnya): ?>
                                                        <tr>
                                                            <td colspan="4" style="font-weight:700; padding-left:18px; background:#f8fafc;">
                                                                <?= e($subSekarang) ?>
                                                            </td>
                                                        </tr>
                                                    <?php endif; ?>
                                                    <?php
                                                    $subSebelumnya = $subSekarang;
                                                    $stKetel = $nilai_ketel_status_lp[$it['key']] ?? '';
                                                    ?>
                                                    <tr>
                                                        <td style="padding-left:32px;"><?= e($it['label']) ?></td>
                                                        <?php foreach (['baik', 'buruk'] as $opsi): ?>
                                                            <td style="text-align:center;">
                                                                <input type="checkbox" class="lp-ketel-chk"
                                                                    name="ketel_status[<?= e($it['key']) ?>]" value="<?= $opsi ?>"
                                                                    <?= $stKetel === $opsi ? 'checked' : '' ?>>
                                                            </td>
                                                        <?php endforeach; ?>
                                                        <td>
                                                            <?php if (!empty($it['field_ket'])): ?>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="ketel_ket[<?= e($it['key']) ?>]" rows="1"
                                                                    oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'"><?= e($nilai_ketel_ket_lp[$it['key']] ?? '') ?></textarea>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Klik "Baik" atau "Buruk" untuk mencentang salah satu. Klik lagi pada kotak yang sudah
                                    tercentang untuk membatalkan pilihan (kosong). Jika tidak ada yang dipilih, kedua kolom
                                    otomatis tercetak "-" di Word.
                                </small>
                            </div>
                            <script>
                                (function () {
                                    document.addEventListener('change', function (e) {
                                        var t = e.target;
                                        if (!t.classList || !t.classList.contains('lp-ketel-chk')) return;
                                        if (t.checked) {
                                            document.querySelectorAll('input.lp-ketel-chk[name="' + CSS.escape(t.name) + '"]').forEach(function (chk) {
                                                if (chk !== t) chk.checked = false;
                                            });
                                        }
                                    });
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if (!empty($fields_visual_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">III. Pemeriksaan Visual</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th style="width:36px;">No</th>
                                                <th>Komponen</th>
                                                <th style="width:110px; text-align:center;">Memenuhi Syarat</th>
                                                <th style="width:130px; text-align:center;">Tidak Memenuhi Syarat</th>
                                                <th style="width:260px;">Keterangan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_visual_lp as $grup): ?>
                                                <tr>
                                                    <td colspan="5" style="background:#eef2ff; font-weight:700; color:#4338ca;">
                                                        <?= e($grup['kode']) ?>. <?= e($grup['judul']) ?>
                                                    </td>
                                                </tr>
                                                <?php foreach ($grup['items'] as $it): ?>
                                                    <?php $st = $nilai_visual_status_lp[$it['key']] ?? ''; ?>
                                                    <tr>
                                                        <td><?= e($it['sub']) ?>.</td>
                                                        <td><?= e($it['label']) ?></td>
                                                        <td style="text-align:center;">
                                                            <input type="checkbox" class="lp-vis-chk"
                                                                name="visual_status[<?= e($it['key']) ?>]" value="ok" <?= $st === 'ok' ? 'checked' : '' ?>>
                                                        </td>
                                                        <td style="text-align:center;">
                                                            <input type="checkbox" class="lp-vis-chk"
                                                                name="visual_status[<?= e($it['key']) ?>]" value="tdk" <?= $st === 'tdk' ? 'checked' : '' ?>>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($it['field_ket'])): ?>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="visual_ket[<?= e($it['key']) ?>]" rows="1"
                                                                    oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'"><?= e($nilai_visual_ket_lp[$it['key']] ?? '') ?></textarea>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Klik "Memenuhi Syarat" atau "Tidak Memenuhi Syarat" untuk mencentang salah satu.
                                    Klik lagi pada kotak yang sudah tercentang untuk membatalkan pilihan (kosong).
                                    Jika tidak ada yang dipilih, kedua kolom otomatis tercetak "-" di Word.
                                </small>
                            </div>
                            <script>
                                (function () {
                                    document.addEventListener('change', function (e) {
                                        var t = e.target;
                                        if (!t.classList || !t.classList.contains('lp-vis-chk')) return;
                                        if (t.checked) {
                                            document.querySelectorAll('input.lp-vis-chk[name="' + CSS.escape(t.name) + '"]').forEach(function (chk) {
                                                if (chk !== t) chk.checked = false;
                                            });
                                        }
                                    });
                                })();
                            </script>
                        <?php endif; ?>

                        <?php if (!empty($fields_dimensi_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">IV. Pemeriksaan Dimensi</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th style="width:36px;">No</th>
                                                <th>Komponen</th>
                                                <th style="width:220px;">Ukuran / Dimensi</th>
                                                <th style="width:260px;">Keterangan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_dimensi_lp as $grup): ?>
                                                <tr>
                                                    <td colspan="4" style="background:#eef2ff; font-weight:700; color:#4338ca;">
                                                        <?= (int) $grup['no'] ?>. <?= e($grup['judul']) ?>
                                                    </td>
                                                </tr>
                                                <?php foreach ($grup['items'] as $it): ?>
                                                    <tr>
                                                        <td><?= e($it['sub']) ?>.</td>
                                                        <td><?= e($it['label']) ?></td>
                                                        <td>
                                                            <input type="text" name="dimensi_nilai[<?= e($it['key']) ?>]"
                                                                class="form-control-custom text-xs"
                                                                placeholder="cth: 5,98 (mm otomatis)"
                                                                value="<?= e($nilai_dimensi_lp[$it['key']] ?? '') ?>">
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($it['field_ket'])): ?>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="dimensi_ket[<?= e($it['key']) ?>]" rows="1"
                                                                    oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px'"><?= e($nilai_dimensi_ket_lp[$it['key']] ?? '') ?></textarea>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fields_thk_lp)): ?>
                            <?php
                            $hintThk = ['thk_efisiensi' => 'Contoh: 0,85', 'thk_ca' => 'Contoh: 1,5', 'thk_stress' => 'Contoh: 1030', 'thk_jari_jari' => 'Contoh: 450'];
                            $manualThk = [
                                'thk_tebal_head_manual' => 'Ketebalan Head',
                                'thk_tebal_shell_manual' => 'Ketebalan Shell',
                            ];
                            // R dipisah supaya tampil PALING BAWAH (setelah Ketebalan Shell)
                            $fieldJariJari = null;
                            foreach ($fields_thk_lp as $f) {
                                if ($f['field'] === 'thk_jari_jari') {
                                    $fieldJariJari = $f;
                                    break;
                                }
                            }
                            ?>
                            <div class="mt-3">
                                <div class="lp-section-title">V. Perhitungan Thickness Test</div>
                                <div class="d-flex flex-column gap-2">

                                    <?php foreach ($fields_thk_lp as $f): ?>
                                        <?php if ($f['field'] === 'thk_jari_jari')
                                            continue; ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <label
                                                class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($f['label']) ?></label>
                                            <input type="text" name="dinamis[<?= e($f['field']) ?>]"
                                                class="form-control-custom text-xs" style="flex:1 1 auto;"
                                                placeholder="<?= e($hintThk[$f['field']] ?? '') ?>"
                                                value="<?= e($nilai_dinamis_lp[$f['field']] ?? '') ?>">
                                        </div>
                                    <?php endforeach; ?>

                                    <?php foreach ($manualThk as $nm => $lbl): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <label class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($lbl) ?></label>
                                            <input type="text" name="dinamis[<?= e($nm) ?>]" class="form-control-custom text-xs"
                                                style="flex:1 1 auto;" value="<?= e($_POST['dinamis'][$nm] ?? '') ?>">
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if ($fieldJariJari): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <label
                                                class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($fieldJariJari['label']) ?></label>
                                            <input type="text" name="dinamis[thk_jari_jari]" class="form-control-custom text-xs"
                                                style="flex:1 1 auto;" placeholder="<?= e($hintThk['thk_jari_jari']) ?>"
                                                value="<?= e($nilai_dinamis_lp['thk_jari_jari'] ?? '') ?>">
                                        </div>
                                    <?php endif; ?>

                                    <small class="text-secondary text-xs">
                                        Ketebalan Head &amp; Shell dihitung otomatis dari Tekanan Kerja (P) di Data Umum.
                                        Isi kolom "manual" hanya jika ingin memakai angka sendiri.
                                        Jari-jari (R) diisi manual; jika kosong, R diambil dari Diameter ÷ 2.
                                    </small>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fields_hasil_lp)): ?>
                            <?php
                            $adaKolomKetHasil = (bool) array_filter($fields_hasil_lp, fn($h) => $h['field_ket']);
                            $adaKolomRujukanHasil = (bool) array_filter($fields_hasil_lp, fn($h) => $h['field_rujukan']); // <<< BARU
                            $adaKolomMetodeHasil = (bool) array_filter($fields_hasil_lp, fn($h) => $h['field_metode']);  // <<< BARU
                            ?>
                            <div class="mt-3">
                                <div class="lp-section-title">III. Data Hasil Pengujian / Pemeriksaan</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th style="width:36px;">No</th>
                                                <th>Obyek / Komponen</th>
                                                <th style="width:220px;">Hasil</th>
                                                <?php if ($adaKolomRujukanHasil): ?>
                                                    <th style="width:220px;">Nilai Rujukan</th><?php endif; ?>
                                                <?php if ($adaKolomMetodeHasil): ?>
                                                    <th style="width:160px;">Metode</th><?php endif; ?>
                                                <?php if ($adaKolomKetHasil): ?>
                                                    <th>Keterangan</th><?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_hasil_lp as $h): ?>
                                                <?php if (!empty($h['sub'])): ?>
                                                    <?php foreach ($h['sub'] as $i => $s): ?>
                                                        <tr>
                                                            <?php if ($i === 0): ?>
                                                                <td rowspan="<?= count($h['sub']) ?>"><?= $h['no'] ?>.</td>
                                                            <?php endif; ?>
                                                            <td><?= e($s['label']) ?></td>
                                                            <td>
                                                                <textarea class="form-control-custom lp-textarea-ket"
                                                                    name="dinamis[<?= e($s['field']) ?>]" rows="1"
                                                                    oninput="lpAutoGrowTextarea(this)"><?= e($nilai_hasil_lp[$s['field']] ?? '') ?></textarea>
                                                            </td>

                                                            <?php if ($i === 0 && $adaKolomRujukanHasil): ?>
                                                                <td rowspan="<?= count($h['sub']) ?>">
                                                                    <?php if ($h['field_rujukan']): ?>
                                                                        <textarea class="form-control-custom lp-textarea-ket"
                                                                            name="dinamis[<?= e($h['field_rujukan']) ?>]" rows="1"
                                                                            oninput="lpAutoGrowTextarea(this)"><?= e($nilai_hasil_lp[$h['field_rujukan']] ?? '') ?></textarea>
                                                                    <?php endif; ?>
                                                                </td>
                                                            <?php endif; ?>

                                                            <?php if ($i === 0 && $adaKolomMetodeHasil): ?>
                                                                <td rowspan="<?= count($h['sub']) ?>">
                                                                    <?php if ($h['field_metode']): ?>
                                                                        <textarea class="form-control-custom lp-textarea-ket"
                                                                            name="dinamis[<?= e($h['field_metode']) ?>]" rows="1"
                                                                            oninput="lpAutoGrowTextarea(this)"><?= e($nilai_hasil_lp[$h['field_metode']] ?? '') ?></textarea>
                                                                    <?php endif; ?>
                                                                </td>
                                                            <?php endif; ?>

                                                            <?php if ($i === 0 && $adaKolomKetHasil): ?>
                                                                <td rowspan="<?= count($h['sub']) ?>">
                                                                    <?php if ($h['field_ket']): ?>
                                                                        <textarea class="form-control-custom lp-textarea-ket"
                                                                            name="dinamis[<?= e($h['field_ket']) ?>]"
                                                                            oninput="lpAutoGrowTextarea(this)"><?= e($nilai_hasil_lp[$h['field_ket']] ?? '') ?></textarea>
                                                                    <?php endif; ?>
                                                                </td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td><?= $h['no'] ?>.</td>
                                                        <td><?= e($h['label']) ?></td>
                                                        <td>
                                                            <textarea class="form-control-custom lp-textarea-ket"
                                                                name="dinamis[<?= e($h['field_nilai']) ?>]" rows="1"
                                                                oninput="lpAutoGrowTextarea(this)"><?= e($nilai_hasil_lp[$h['field_nilai']] ?? '') ?></textarea>
                                                        </td>
                                                        <?php if ($adaKolomRujukanHasil): ?>
                                                            <td>
                                                                <?php if ($h['field_rujukan']): ?>
                                                                    <textarea class="form-control-custom lp-textarea-ket"
                                                                        name="dinamis[<?= e($h['field_rujukan']) ?>]" rows="1"
                                                                        oninput="lpAutoGrowTextarea(this)"><?= e($nilai_hasil_lp[$h['field_rujukan']] ?? '') ?></textarea>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if ($adaKolomMetodeHasil): ?>
                                                            <td>
                                                                <?php if ($h['field_metode']): ?>
                                                                    <textarea class="form-control-custom lp-textarea-ket"
                                                                        name="dinamis[<?= e($h['field_metode']) ?>]" rows="1"
                                                                        oninput="lpAutoGrowTextarea(this)"><?= e($nilai_hasil_lp[$h['field_metode']] ?? '') ?></textarea>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <?php if ($adaKolomKetHasil): ?>
                                                            <td>
                                                                <?php if ($h['field_ket']): ?>
                                                                    <textarea class="form-control-custom lp-textarea-ket"
                                                                        name="dinamis[<?= e($h['field_ket']) ?>]" rows="1"
                                                                        oninput="lpAutoGrowTextarea(this)"><?= e($nilai_hasil_lp[$h['field_ket']] ?? '') ?></textarea>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                    </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>


                        <?php if (!empty($fields_arus_nominal_lp) || !empty($fields_kabel_lp) || !empty($fields_proteksi_nominal_lp) || !empty($fields_rst_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">IV. ANALISIS</div>

                                <?php if (!empty($fields_arus_nominal_lp)): ?>
                                    <div class="lp-subsection">
                                        <div class="lp-subsection-title">A. Perhitungan Arus Nominal (In)</div>
                                        <div class="lp-subsection-body">
                                            <?php foreach ($fields_arus_nominal_lp as $f): ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <label
                                                        class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($f['label']) ?></label>
                                                    <input type="text" name="dinamis[<?= e($f['field']) ?>]"
                                                        class="form-control-custom text-xs" style="flex:1 1 auto;"
                                                        value="<?= e($nilai_dinamis_lp[$f['field']] ?? '') ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($fields_kabel_lp)): ?>
                                    <div class="lp-subsection">
                                        <div class="lp-subsection-title">B. Jenis dan Ukuran Kabel yang Digunakan</div>
                                        <div class="lp-subsection-body">
                                            <?php foreach ($fields_kabel_lp as $f): ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <label
                                                        class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($f['label']) ?></label>
                                                    <div class="d-flex align-items-center gap-1" style="flex:1 1 auto;">
                                                        <input type="text" name="dinamis[<?= e($f['field']) ?>]"
                                                            class="form-control-custom text-xs" style="flex:1 1 auto;"
                                                            value="<?= e($nilai_dinamis_lp[$f['field']] ?? '') ?>"
                                                            <?= $f['field'] === 'kabel_ukuran' ? 'placeholder="Contoh: 8 x 185"' : '' ?>
                                                            <?= $f['field'] === 'kabel_kha_satuan' ? 'placeholder="Contoh: 637"' : '' ?>>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($fields_proteksi_nominal_lp)): ?>
                                    <div class="lp-subsection">
                                        <div class="lp-subsection-title">C. Perhitungan Pembatas Arus atau Rating Proteksi Utama
                                        </div>
                                        <div class="lp-subsection-body">
                                            <?php foreach ($fields_proteksi_nominal_lp as $f): ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <label
                                                        class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($f['label']) ?></label>
                                                    <input type="text" name="dinamis[<?= e($f['field']) ?>]"
                                                        class="form-control-custom text-xs" style="flex:1 1 auto;"
                                                        value="<?= e($nilai_dinamis_lp[$f['field']] ?? '') ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($fields_rst_lp)): ?>
                                    <div class="lp-subsection">
                                        <div class="lp-subsection-title">D. Perhitungan Keseimbangan Beban RST</div>
                                        <div class="lp-subsection-body">
                                            <?php foreach ($fields_rst_lp as $f): ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <label
                                                        class="form-label fw-semibold mb-0 text-xs lp-field-label"><?= e($f['label']) ?></label>
                                                    <input type="text" name="dinamis[<?= e($f['field']) ?>]"
                                                        class="form-control-custom text-xs" style="flex:1 1 auto;"
                                                        value="<?= e($nilai_dinamis_lp[$f['field']] ?? '') ?>">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($fields_checklist_lp)): ?>
                            <div class="mt-3">
                                <div class="lp-section-title">Data Checklist Pemeriksaan</div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom">
                                        <thead>
                                            <tr>
                                                <th style="width:36px;">No</th>
                                                <th>Nama Barang yang Diperiksa</th>
                                                <th style="width:70px; text-align:center;">Baik</th>
                                                <th style="width:90px; text-align:center;">Tidak Baik</th>
                                                <th style="width:260px;">Keterangan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fields_checklist_lp as $item): ?>
                                                <?php
                                                $keyCek = 'cek' . $item['no'];
                                                $statusTerpilih = $nilai_checklist_lp[$keyCek] ?? '';
                                                ?>
                                                <tr>
                                                    <td><?= $item['no'] ?>.</td>
                                                    <td style="white-space:nowrap;"><?= e($item['label']) ?></td>
                                                    <td style="text-align:center;">
                                                        <input type="checkbox" class="lp-cek-chk" name="checklist[<?= $keyCek ?>]"
                                                            value="baik" <?= $statusTerpilih === 'baik' ? 'checked' : '' ?>>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <input type="checkbox" class="lp-cek-chk" name="checklist[<?= $keyCek ?>]"
                                                            value="tidak" <?= $statusTerpilih === 'tidak' ? 'checked' : '' ?>>
                                                    </td>
                                                    <?php if (($item['field_ket'] ?? null) && ($item['ket_tampilkan'] ?? true)): ?>
                                                        <td rowspan="<?= (int) ($item['ket_span'] ?? 1) ?>">
                                                            <textarea class="form-control-custom lp-textarea-ket"
                                                                name="keterangan[<?= $keyCek ?>]" rows="1"
                                                                oninput="lpAutoGrowTextarea(this)"><?= e($nilai_keterangan_checklist_lp[$keyCek] ?? '') ?></textarea>
                                                        </td>
                                                    <?php elseif (empty($item['field_ket'])): ?>
                                                        <td></td>
                                                    <?php endif; ?>
                                                    <!-- kalau field_ket ada TAPI ket_tampilkan false -> jangan cetak <td> sama sekali, 
                                                            karena sudah tercakup rowspan dari baris "awal" grup -->
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-secondary text-xs">
                                    Klik salah satu kotak (Baik / Tidak Baik) untuk mencentang. Klik lagi pada kotak yang sudah
                                    tercentang
                                    untuk membatalkan pilihan (kosong). Jika tidak ada yang dipilih, kedua kolom otomatis
                                    tercetak "-" di Word.
                                </small>
                            </div>
                            <script>
                                function lpAutoGrowTextarea(el) {
                                    el.style.height = 'auto';
                                    el.style.height = (el.scrollHeight) + 'px';
                                }
                                (function () {
                                    document.querySelectorAll('.lp-textarea-ket').forEach(function (el) {
                                        lpAutoGrowTextarea(el);
                                    });
                                })();
                                document.addEventListener('change', function (e) {
                                    var t = e.target;
                                    if (!t.classList || !t.classList.contains('lp-cek-chk')) return;
                                    if (t.checked) {
                                        document.querySelectorAll('input.lp-cek-chk[name="' + CSS.escape(t.name) + '"]').forEach(function (chk) {
                                            if (chk !== t) chk.checked = false;
                                        });
                                    }
                                });
                            </script>
                        <?php endif; ?>

                        <?php if (!empty($fields_tabel_lp)): ?>
                            <div class="mt-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="lp-section-title" style="margin-bottom:0; flex:1 1 auto;">Rincian / Temuan
                                        Pemeriksaan</div>
                                    <button type="button" class="btn-secondary-custom" style="font-size:0.75rem;"
                                        onclick="lpTambahBarisItem()">
                                        <i class="bi bi-plus-lg"></i> Tambah Baris
                                    </button>
                                </div>
                                <div class="table-responsive-custom">
                                    <table class="table-custom" id="lp-tabel-item">
                                        <thead>
                                            <tr>
                                                <th style="width:36px;">No</th>
                                                <?php foreach ($fields_tabel_lp as $kolom): ?>
                                                    <th><?= e($kolom['label']) ?></th>
                                                <?php endforeach; ?>
                                                <th style="width:40px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="lp-tabel-item-body">
                                            <?php foreach ($nilai_items_lp as $idxBaris => $baris): ?>
                                                <tr class="baris-item">
                                                    <td class="nomor-baris"><?= $idxBaris + 1 ?></td>
                                                    <?php foreach ($fields_tabel_lp as $kolom): ?>
                                                        <?php $isTanggalKolom = lp_is_kolom_tanggal($kolom['field']); ?>
                                                        <td>
                                                            <?php if ($kolom['field'] === 'keterangan'): ?>
                                                                <div class="d-flex align-items-start gap-1">
                                                                    <textarea class="form-control-custom lp-textarea-ket"
                                                                        name="items[<?= (int) $idxBaris ?>][keterangan]" rows="1"
                                                                        oninput="lpAutoGrowTextarea(this)"><?= e($baris['keterangan'] ?? '') ?></textarea>
                                                                    <button type="button"
                                                                        class="btn btn-outline-secondary btn-sm py-1 lp-btn-saran"
                                                                        style="font-size:0.7rem; white-space:nowrap;"
                                                                        title="Isi otomatis berdasarkan nilai tahanan di baris ini"
                                                                        onclick="pasangAutoSaran(this)">
                                                                        <i class="bi bi-magic"></i> Saran
                                                                    </button>
                                                                </div>
                                                            <?php elseif ($kolom['field'] === 'tahanan'): ?>
                                                                <div class="d-flex align-items-center gap-1 lp-tahanan-group">
                                                                    <span class="lp-label-r text-secondary text-xs"
                                                                        style="white-space:nowrap;">R<?= $idxBaris + 1 ?> =</span>
                                                                    <input type="text" name="items[<?= (int) $idxBaris ?>][tahanan]"
                                                                        class="form-control-custom lp-input-tahanan"
                                                                        value="<?= e($baris['tahanan'] ?? '') ?>">
                                                                    <span class="text-secondary text-xs">Ω</span>
                                                                </div>
                                                            <?php else: ?>
                                                                <input type="<?= $isTanggalKolom ? 'date' : 'text' ?>"
                                                                    name="items[<?= (int) $idxBaris ?>][<?= e($kolom['field']) ?>]"
                                                                    class="form-control-custom"
                                                                    value="<?= e($baris[$kolom['field']] ?? '') ?>">
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                    <td style="text-align:center;">
                                                        <button type="button" class="btn btn-outline-danger btn-sm py-1"
                                                            style="font-size:0.7rem;" title="Hapus baris"
                                                            onclick="lpHapusBarisItem(this)">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <script>
                                // ============== Rincian / Temuan Pemeriksaan: tambah/hapus baris ==============
                                function lpTambahBarisItem() {
                                    var tbody = document.getElementById('lp-tabel-item-body');
                                    if (!tbody) return;
                                    var semuaBaris = tbody.querySelectorAll('tr.baris-item');
                                    var barisPertama = semuaBaris[0];
                                    if (!barisPertama) return;
                                    var barisTerakhir = semuaBaris[semuaBaris.length - 1];

                                    // "Alat" biasanya sama untuk semua titik pengukuran dalam satu laporan,
                                    // jadi nilai yang sudah pernah diisi TIDAK ikut dikosongkan/diketik ulang
                                    // tiap kali tambah baris -- ambil dari baris terakhir yang sudah diisi.
                                    var inputAlatLama = barisTerakhir.querySelector('[name$="[alat]"]');
                                    var nilaiAlatLama = inputAlatLama ? inputAlatLama.value : '';

                                    var barisBaru = barisPertama.cloneNode(true);

                                    barisBaru.querySelectorAll('input, textarea').forEach(function (el) {
                                        var name = el.getAttribute('name') || '';
                                        if (/\[alat\]$/.test(name)) {
                                            el.value = nilaiAlatLama; // pertahankan nilai alat yang sudah diisi
                                            return;
                                        }
                                        el.value = '';
                                        if (el.tagName === 'TEXTAREA') el.style.height = 'auto';
                                    });

                                    tbody.appendChild(barisBaru);
                                    lpRenumberBarisItem();
                                }

                                function lpHapusBarisItem(btn) {
                                    var tbody = document.getElementById('lp-tabel-item-body');
                                    var baris = btn.closest('tr.baris-item');
                                    if (!tbody || !baris) return;

                                    // Jangan sampai tabel kosong total, minimal sisakan 1 baris (dikosongkan saja isinya)
                                    if (tbody.querySelectorAll('tr.baris-item').length <= 1) {
                                        baris.querySelectorAll('input, textarea').forEach(function (el) { el.value = ''; });
                                        return;
                                    }
                                    baris.remove();
                                    lpRenumberBarisItem();
                                }

                                function lpRenumberBarisItem() {
                                    var tbody = document.getElementById('lp-tabel-item-body');
                                    if (!tbody) return;

                                    tbody.querySelectorAll('tr.baris-item').forEach(function (tr, idx) {
                                        var no = idx + 1;

                                        var elNomor = tr.querySelector('.nomor-baris');
                                        if (elNomor) elNomor.textContent = no;

                                        // Label R1 =, R2 =, dst mengikuti nomor baris tahanan
                                        var elLabelR = tr.querySelector('.lp-label-r');
                                        if (elLabelR) elLabelR.textContent = 'R' + no + ' =';

                                        // Reindex name="items[idx][...]" biar urut & konsisten saat submit
                                        tr.querySelectorAll('input, textarea').forEach(function (el) {
                                            var name = el.getAttribute('name');
                                            if (!name) return;
                                            el.setAttribute('name', name.replace(/^items\[\d+\]/, 'items[' + idx + ']'));
                                        });
                                    });
                                }

                                // ============== Auto-saran Keterangan (berdasarkan nilai tahanan di baris yang sama) ==============
                                function saranKeterangan(nilaiTahanan) {
                                    var angka = parseFloat(String(nilaiTahanan).replace(',', '.'));
                                    if (isNaN(angka)) return '';

                                    var status = angka <= 5 ? 'Memenuhi' : 'Tidak Memenuhi';
                                    return 'Didapatkan hasil pengujian ' + status + '.'/* ', dikarenakan sesuai dengan Permenaker No. 2 '
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            + 'tahun 1989 pasal 54 bahwa nilai maksimal pembumian tidak boleh lebih dari 5 ohm.'
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            + (angka <= 5
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                ? ' (nilai tahanan ' + angka + ' Ω).'
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                : ' (nilai tahanan ' + angka + ' Ω melebihi batas maksimal).'); */
                                }

                                function pasangAutoSaran(btn) {
                                    var baris = btn.closest('tr.baris-item');
                                    if (!baris) return;

                                    var inputTahanan = baris.querySelector('.lp-input-tahanan');
                                    var textareaKet = baris.querySelector('.lp-textarea-ket');
                                    if (!inputTahanan || !textareaKet) return;

                                    var teksSaran = saranKeterangan(inputTahanan.value);
                                    if (teksSaran === '') {
                                        alert('Isi dahulu nilai Tahanan pada baris ini untuk mendapatkan saran keterangan.');
                                        return; arpAlert('Isi dahulu nilai Tahanan pada baris ini untuk mendapatkan saran keterangan.',
                                            { title: 'Nilai Tahanan Kosong', type: 'warning' });
                                        return;
                                    }
                                    textareaKet.value = teksSaran;
                                    lpAutoGrowTextarea(textareaKet);
                                }
                            </script>
                        <?php endif; ?>

                        <div class="d-flex gap-2 mt-4 lp-form-actions">
                            <button type="submit" name="preview_only" value="1" class="btn-secondary-custom"
                                data-arp-loading="Memperbarui pratinjau...">
                                <i class="bi bi-arrow-repeat"></i> Update Preview
                            </button>
                            <button type="submit" class="btn-primary-custom"
                                data-arp-loading="Menyimpan &amp; membuat laporan...">
                                <i class="bi bi-file-earmark-check"></i>
                                <?= $editId ? 'Simpan Perubahan' : 'Simpan &amp; Buat Laporan' ?>
                            </button>
                        </div>
                    </form>

                    <script>
                        (function () {
                            function lpInitWizardBuatLaporan() {
                                var form = document.getElementById('form-buat-laporan');
                                if (!form || form.dataset.lpWizardInit) return;
                                form.dataset.lpWizardInit = '1';

                                var tombolAksi = form.querySelector('.lp-form-actions');
                                var inputStep = document.getElementById('lp-wizard-step');
                                var anak = Array.prototype.slice.call(form.children);

                                function judulLangsung(el) {
                                    if (!el.classList) return false;
                                    if (el.classList.contains('lp-section-title')) return true;
                                    var a = el.firstElementChild;
                                    return !!(a && a.classList && a.classList.contains('lp-section-title'));
                                }
                                function teksJudul(el) {
                                    if (el.classList.contains('lp-section-title')) return el.textContent.trim();
                                    var j = el.querySelector(':scope > .lp-section-title');
                                    return j ? j.textContent.trim() : '';
                                }

                                // Kelompokkan children (kecuali hidden input & blok tombol aksi) jadi langkah-langkah
                                var stepsData = [];
                                var stepSaatIni = null;

                                anak.forEach(function (el) {
                                    if (el.tagName === 'INPUT' && el.type === 'hidden') return;
                                    if (el === tombolAksi) return;

                                    if (judulLangsung(el) || stepSaatIni === null) {
                                        stepSaatIni = { judul: teksJudul(el) || 'Data Umum', elemen: [] };
                                        stepsData.push(stepSaatIni);
                                    }
                                    stepSaatIni.elemen.push(el);
                                });

                                if (stepsData.length <= 1) return; // isi form pendek, tidak perlu wizard

                                // Pindahkan tiap kelompok ke dalam <div class="lp-step">
                                var containerSteps = document.createElement('div');
                                containerSteps.className = 'lp-wizard-steps';
                                stepsData.forEach(function (s, idx) {
                                    var div = document.createElement('div');
                                    div.className = 'lp-step' + (idx === 0 ? ' active' : '');
                                    s.elemen.forEach(function (el) { div.appendChild(el); }); // memindahkan node asli, bukan menyalin
                                    containerSteps.appendChild(div);
                                });

                                // Panel navigasi atas (judul langkah + titik indikator)
                                var navTop = document.createElement('div');
                                navTop.className = 'lp-wizard-nav';
                                navTop.innerHTML =
                                    '<div>' +
                                    '  <div class="lp-wizard-title" id="lpWizardTitle"></div>' +
                                    '  <div class="lp-wizard-progress" id="lpWizardProgress"></div>' +
                                    '</div>' +
                                    '<div class="lp-wizard-dots" id="lpWizardDots"></div>';

                                // Tombol navigasi bawah
                                var navBottom = document.createElement('div');
                                navBottom.className = 'lp-wizard-btns';
                                navBottom.innerHTML =
                                    '<button type="button" class="btn-secondary-custom" id="lpWizardPrev">' +
                                    '<i class="bi bi-arrow-left"></i> Sebelumnya</button>' +
                                    '<button type="button" class="btn-primary-custom" id="lpWizardNext">' +
                                    'Selanjutnya <i class="bi bi-arrow-right"></i></button>';

                                if (tombolAksi) {
                                    form.insertBefore(navTop, tombolAksi);
                                    form.insertBefore(containerSteps, tombolAksi);
                                    form.insertBefore(navBottom, tombolAksi);
                                } else {
                                    form.appendChild(navTop);
                                    form.appendChild(containerSteps);
                                    form.appendChild(navBottom);
                                }

                                var dotsBox = navTop.querySelector('#lpWizardDots');
                                stepsData.forEach(function (s, idx) {
                                    var b = document.createElement('button');
                                    b.type = 'button';
                                    b.className = 'lp-wizard-dot' + (idx === 0 ? ' active' : '');
                                    b.title = s.judul;
                                    b.dataset.goto = idx;
                                    dotsBox.appendChild(b);
                                });

                                var totalStep = stepsData.length;
                                var stepEls = Array.prototype.slice.call(containerSteps.querySelectorAll('.lp-step'));
                                var idxSekarang = 0;
                                if (inputStep) {
                                    var v = parseInt(inputStep.value, 10);
                                    if (!isNaN(v) && v >= 0 && v < totalStep) idxSekarang = v;
                                }

                                function tampilkan(idx) {
                                    idxSekarang = Math.max(0, Math.min(totalStep - 1, idx));
                                    stepEls.forEach(function (el, i) { el.classList.toggle('active', i === idxSekarang); });
                                    dotsBox.querySelectorAll('.lp-wizard-dot').forEach(function (d, i) {
                                        d.classList.toggle('active', i === idxSekarang);
                                        d.classList.toggle('done', i < idxSekarang);
                                    });
                                    navTop.querySelector('#lpWizardTitle').textContent = stepsData[idxSekarang].judul;
                                    navTop.querySelector('#lpWizardProgress').textContent =
                                        'Langkah ' + (idxSekarang + 1) + ' dari ' + totalStep;
                                    navBottom.querySelector('#lpWizardPrev').style.visibility = idxSekarang === 0 ? 'hidden' : 'visible';
                                    navBottom.querySelector('#lpWizardNext').style.display =
                                        idxSekarang === totalStep - 1 ? 'none' : 'inline-flex';
                                    if (tombolAksi) tombolAksi.style.display = idxSekarang === totalStep - 1 ? '' : 'none';
                                    if (inputStep) inputStep.value = idxSekarang;
                                    navTop.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                }

                                navBottom.querySelector('#lpWizardNext').addEventListener('click', function () { tampilkan(idxSekarang + 1); });
                                navBottom.querySelector('#lpWizardPrev').addEventListener('click', function () { tampilkan(idxSekarang - 1); });
                                dotsBox.addEventListener('click', function (e) {
                                    var d = e.target.closest('.lp-wizard-dot');
                                    if (d) tampilkan(parseInt(d.dataset.goto, 10));
                                });

                                form.addEventListener('submit', function () {
                                    if (inputStep) inputStep.value = idxSekarang;
                                });

                                tampilkan(idxSekarang);
                            }

                            document.addEventListener('DOMContentLoaded', lpInitWizardBuatLaporan);
                        })();
                    </script>
                <?php elseif (!$idKategoriTerpilih): ?>
                    <p class="text-secondary text-xs mt-2 mb-0">Silakan pilih Bidang Objek, Unit Objek, dan Template
                        untuk mulai mengisi data laporan.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================== TAB 3: UPLOAD TEMPLATE LAPORAN ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelTemplateLaporan" <?= $active_tab === 'tabPanelTemplateLaporan' ? '' : 'style="display:none;"' ?>>
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Template Laporan</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari nama template atau kode..."
                                data-table-search="tabelTemplateLaporan"
                                onkeyup="handleTableSearch('tabelTemplateLaporan')">
                        </div>
                        <button class="btn-primary-custom" onclick="openModal('modalUploadTemplateLaporan')">
                            <i class="bi bi-cloud-upload"></i> Upload Template
                        </button>
                    </div>
                </div>
                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelTemplateLaporan">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Template</th>
                                <th>Kode Laporan</th>
                                <th>Bidang Objek</th>
                                <th>Unit Objek</th>
                                <th>Tanggal Upload</th>
                                <th>Status</th>
                                <th class="col-aksi" style="text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($daftar_template_laporan)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="bi bi-file-earmark-x d-block mb-2" style="font-size:2rem;"></i>
                                        Belum ada template laporan.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php $no = 1; ?>
                            <?php foreach ($daftar_template_laporan as $t): ?>
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
                                    <td><span class="badge-warning"><?= e($t['kode_laporan']) ?></span></td>
                                    <td><?= e($t['nama_kategori']) ?></td>
                                    <td><?= e($t['nama_objek']) ?></td>
                                    <td><?= date('d-m-Y', strtotime($t['created_at'])) ?></td>
                                    <td>
                                        <?php if (!empty($t['drive_file_id'])): ?>
                                            <span class="badge-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-danger">Hilang</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-aksi" style="text-align:center;">
                                        <div class="table-actions">
                                            <?php if (!empty($t['drive_file_id'])): ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="https://docs.google.com/document/d/<?= e($t['drive_file_id']) ?>/edit"
                                                    target="_blank" title="Lihat &amp; Edit Word di Drive"
                                                    data-arp-loading="Membuka Google Drive...">
                                                    <i class="bi bi-file-earmark-word"></i>
                                                </a>

                                                <?php if (($t['format'] ?? '') === 'word_pdf'): ?>
                                                    <form method="POST" action="pemeriksaan.php" class="d-inline">
                                                        <input type="hidden" name="aksi" value="sinkron_template_laporan">
                                                        <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                                                        <input type="hidden" name="redirect_tab" value="template">
                                                        <button type="submit" class="btn btn-outline-primary btn-sm py-1"
                                                            style="font-size:0.75rem;"
                                                            title="Sinkronkan field dari file Word terbaru (setelah selesai edit di Drive)"
                                                            data-arp-loading="Menyinkronkan field dari Word...">
                                                            <i class="bi bi-arrow-repeat"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <button type="button" class="btn btn-outline-secondary btn-sm py-1"
                                                style="font-size:0.75rem;" title="Edit template"
                                                data-id="<?= (int) $t['id'] ?>" data-nama="<?= e($t['nama']) ?>"
                                                data-kode="<?= e($t['kode_laporan']) ?>"
                                                data-deskripsi="<?= e($t['deskripsi'] ?? '') ?>"
                                                data-kategori="<?= (int) $t['id_kategori'] ?>"
                                                data-jenis="<?= (int) $t['id_jenis'] ?>" onclick="lpEditTemplate(this)">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>

                                            <form method="POST" action="pemeriksaan.php" class="d-inline"
                                                data-confirm="Hapus template &quot;<?= e($t['nama']) ?>&quot;?">
                                                <input type="hidden" name="aksi" value="hapus_template_laporan"> <input
                                                    type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                                                <button type="submit" class="btn-danger-custom"
                                                    style="height:28px; padding:0 8px; font-size:0.75rem;"
                                                    data-arp-loading="Menghapus...">
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
                <div class="pagination-custom" id="pagination-tabelTemplateLaporan"></div>
            </div>
        </div>
    </div>
</main>

<!-- Modal: Upload Template Laporan -->
<div class="arp-modal-overlay" id="modalUploadTemplateLaporan"
    onclick="closeModalOutside(event,'modalUploadTemplateLaporan')">
    <div class="arp-modal-box" style="max-width:650px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Upload Template Laporan Pemeriksaan</h5>
                <small class="text-muted">Placeholder <code>${...}</code> dan tabel <code>${item_...}</code> di file
                    Word akan otomatis terdeteksi.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalUploadTemplateLaporan')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="pemeriksaan.php" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="upload_template_laporan">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Bidang Objek *</label>
                        <select name="id_kategori" id="tpl-lp-kategori" class="select-custom" required>
                            <option value="">-- Pilih bidang objek --</option>
                            <?php foreach ($daftar_kategori_objek as $k): ?>
                                <option value="<?= (int) $k['id_kategori'] ?>"><?= e($k['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Unit Objek *</label>
                        <select name="id_jenis" id="tpl-lp-jenis" class="select-custom" required disabled>
                            <option value="">-- Pilih bidang objek dahulu --</option>
                        </select>
                    </div>
                </div>

                <script>
                    (function () {
                        var selKategori = document.getElementById('tpl-lp-kategori');
                        var selJenis = document.getElementById('tpl-lp-jenis');
                        if (!selKategori || !selJenis) return;
                        selKategori.addEventListener('change', function () {
                            var idKategori = this.value;
                            selJenis.innerHTML = '<option value="">Memuat...</option>';
                            selJenis.disabled = true;
                            if (!idKategori) {
                                selJenis.innerHTML = '<option value="">-- Pilih bidang objek dahulu --</option>';
                                return;
                            }
                            fetch('pemeriksaan.php?ajax=unit_objek_by_bidang&id_kategori=' + idKategori)
                                .then(r => r.json()).then(function (daftar) {
                                    selJenis.innerHTML = '<option value="">-- Pilih unit objek --</option>';
                                    daftar.forEach(function (j) {
                                        var opt = document.createElement('option');
                                        opt.value = j.id_jenis;
                                        opt.textContent = j.nama_objek;
                                        selJenis.appendChild(opt);
                                    });
                                    selJenis.disabled = false;
                                });
                        });
                    })();
                </script>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Kode Laporan *</label>
                        <input type="text" name="kode_laporan" class="form-control-custom"
                            style="text-transform:uppercase;" placeholder="Contoh: LP-PAA" required>
                        <small class="text-secondary text-xs d-block mt-1">Dipakai untuk penomoran otomatis
                            laporan.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Nama Template *</label>
                        <input type="text" name="nama_template" class="form-control-custom"
                            placeholder="Contoh: Laporan Riksa Uji Forklift" required>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2">Deskripsi</label>
                    <input type="text" name="deskripsi" class="form-control-custom" placeholder="Opsional">
                </div>

                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2">File Template *</label>
                    <input type="file" name="file_template" class="form-control-custom" accept=".doc,.docx,.pdf"
                        required style="padding-top:8px;">
                    <small class="text-muted d-block mt-1">Format: DOCX (disarankan) atau PDF.</small>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalUploadTemplateLaporan')">Batal</button>
                    <button type="submit" class="btn-primary-custom" data-arp-loading="Mengunggah template...">
                        <i class="bi bi-cloud-upload"></i> Upload Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Template Laporan -->
<div class="arp-modal-overlay" id="modalEditTemplateLaporan"
    onclick="closeModalOutside(event,'modalEditTemplateLaporan')">
    <div class="arp-modal-box" style="max-width:650px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Edit Template Laporan</h5>
                <small class="text-muted">Ubah kode, nama, bidang, atau unit objek template.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalEditTemplateLaporan')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="pemeriksaan.php">
                <input type="hidden" name="aksi" value="edit_template_laporan">
                <input type="hidden" name="template_id" id="edit-tpl-id">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Bidang Objek *</label>
                        <select name="id_kategori" id="edit-tpl-kategori" class="select-custom" required>
                            <option value="">-- Pilih bidang objek --</option>
                            <?php foreach ($daftar_kategori_objek as $k): ?>
                                <option value="<?= (int) $k['id_kategori'] ?>"><?= e($k['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Unit Objek *</label>
                        <select name="id_jenis" id="edit-tpl-jenis" class="select-custom" required></select>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Kode Laporan *</label>
                        <input type="text" name="kode_laporan" id="edit-tpl-kode" class="form-control-custom"
                            style="text-transform:uppercase;" required>
                        <small class="text-secondary text-xs d-block mt-1">
                            Laporan lama tetap memakai kode lama di nomornya. Penomoran untuk kode baru dimulai dari
                            001.
                        </small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Nama Template *</label>
                        <input type="text" name="nama_template" id="edit-tpl-nama" class="form-control-custom" required>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2">Deskripsi</label>
                    <input type="text" name="deskripsi" id="edit-tpl-deskripsi" class="form-control-custom"
                        placeholder="Opsional">
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalEditTemplateLaporan')">Batal</button>
                    <button type="submit" class="btn-primary-custom" data-arp-loading="Menyimpan perubahan...">
                        <i class="bi bi-check-lg"></i> Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        var selKat = document.getElementById('edit-tpl-kategori');
        var selJenis = document.getElementById('edit-tpl-jenis');

        function muatUnit(idKategori, idJenisTerpilih) {
            selJenis.innerHTML = '<option value="">Memuat...</option>';
            selJenis.disabled = true;
            if (!idKategori) {
                selJenis.innerHTML = '<option value="">-- Pilih bidang objek dahulu --</option>';
                return;
            }
            fetch('pemeriksaan.php?ajax=unit_objek_by_bidang&id_kategori=' + idKategori)
                .then(function (r) { return r.json(); })
                .then(function (daftar) {
                    selJenis.innerHTML = '<option value="">-- Pilih unit objek --</option>';
                    daftar.forEach(function (j) {
                        var opt = document.createElement('option');
                        opt.value = j.id_jenis;
                        opt.textContent = j.nama_objek;
                        if (String(j.id_jenis) === String(idJenisTerpilih)) opt.selected = true;
                        selJenis.appendChild(opt);
                    });
                    selJenis.disabled = false;
                });
        }

        selKat.addEventListener('change', function () { muatUnit(this.value, ''); });

        window.lpEditTemplate = function (btn) {
            document.getElementById('edit-tpl-id').value = btn.dataset.id;
            document.getElementById('edit-tpl-nama').value = btn.dataset.nama;
            document.getElementById('edit-tpl-kode').value = btn.dataset.kode;
            document.getElementById('edit-tpl-deskripsi').value = btn.dataset.deskripsi;
            selKat.value = btn.dataset.kategori;
            muatUnit(btn.dataset.kategori, btn.dataset.jenis);
            openModal('modalEditTemplateLaporan');
        };
    })();
</script>

<!-- Modal: Upload Laporan Manual (tanpa template) -->
<div class="arp-modal-overlay" id="modalUploadLaporanManual"
    onclick="closeModalOutside(event,'modalUploadLaporanManual')">
    <div class="arp-modal-box" style="max-width:600px;">
        <div class="arp-modal-header">
            <div>
                <h5 class="fw-bold mb-0">Upload Laporan Pemeriksaan</h5>
                <small class="text-muted">Untuk laporan yang sudah jadi (hasil scan/kerjaan manual), tanpa perlu
                    digenerate dari template.</small>
            </div>
            <button class="arp-modal-close" onclick="closeModal('modalUploadLaporanManual')">&times;</button>
        </div>
        <div class="arp-modal-body">
            <form method="POST" action="pemeriksaan.php" enctype="multipart/form-data">
                <input type="hidden" name="aksi" value="upload_laporan_manual">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Nomor Laporan *</label>
                        <input type="text" name="nomor_laporan" class="form-control-custom" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Tanggal Buat</label>
                        <input type="date" name="tanggal_buat" class="form-control-custom" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2">Nama Perusahaan *</label>
                    <input type="text" name="nama_perusahaan" class="form-control-custom" required>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Bidang Objek *</label>
                        <select name="id_kategori" id="manual-lp-kategori" class="select-custom" required>
                            <option value="">-- Pilih bidang objek --</option>
                            <?php foreach ($daftar_kategori_objek as $k): ?>
                                <option value="<?= (int) $k['id_kategori'] ?>"><?= e($k['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Unit Objek *</label>
                        <select name="id_jenis" id="manual-lp-jenis" class="select-custom" required disabled>
                            <option value="">-- Pilih bidang objek dahulu --</option>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Unit Objek (spesifik)</label>
                        <input type="text" name="unit_objek_spesifik" class="form-control-custom"
                            placeholder="Cth: Forklift Unit 2 (opsional)">
                        <small class="text-secondary text-xs d-block mt-1">Kosongkan untuk memakai nama unit objek di
                            atas.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-2">Jenis Pemeriksaan</label>
                        <select name="jenis_pemeriksaan" class="select-custom">
                            <option value="Pemeriksaan Berkala">Pemeriksaan Berkala</option>
                            <option value="Pemeriksaan Baru">Pemeriksaan Baru</option>
                        </select>
                    </div>
                </div>

                <script>
                    (function () {
                        var selKategori = document.getElementById('manual-lp-kategori');
                        var selJenis = document.getElementById('manual-lp-jenis');
                        if (!selKategori || !selJenis) return;
                        selKategori.addEventListener('change', function () {
                            var idKategori = this.value;
                            selJenis.innerHTML = '<option value="">Memuat...</option>';
                            selJenis.disabled = true;
                            if (!idKategori) {
                                selJenis.innerHTML = '<option value="">-- Pilih bidang objek dahulu --</option>';
                                return;
                            }
                            fetch('pemeriksaan.php?ajax=unit_objek_by_bidang&id_kategori=' + idKategori)
                                .then(r => r.json()).then(function (daftar) {
                                    selJenis.innerHTML = '<option value="">-- Pilih unit objek --</option>';
                                    daftar.forEach(function (j) {
                                        var opt = document.createElement('option');
                                        opt.value = j.id_jenis;
                                        opt.textContent = j.nama_objek;
                                        selJenis.appendChild(opt);
                                    });
                                    selJenis.disabled = false;
                                });
                        });
                    })();
                </script>
                <div class="mt-3">
                    <label class="form-label fw-semibold mb-2">File Laporan *</label>
                    <input type="file" name="file_laporan" class="form-control-custom"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required style="padding-top:8px;">
                </div>
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="btn-secondary-custom"
                        onclick="closeModal('modalUploadLaporanManual')">Batal</button>
                    <button type="submit" class="btn-primary-custom"
                        data-arp-loading="Mengunggah laporan...">Unggah</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelDaftarLaporan', 10);
        initTablePagination('tabelTemplateLaporan', 10);
    });
</script>

<?php if ($errorGenerateLaporan): ?>
    <script>document.addEventListener('DOMContentLoaded', function () { switchTab('tabPanelBuatLaporan', document.querySelector('[data-tab-target=tabPanelBuatLaporan]')); });</script>
<?php endif; ?>

<script>
    // Pengaman: form POST mana pun yang belum punya data-arp-loading tetap menampilkan loading.
    // Dilewati jika submit dibatalkan (mis. confirm() hapus ditolak).
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (e.defaultPrevented || !f || f.tagName !== 'FORM') return;
        if ((f.method || '').toLowerCase() !== 'post') return;
        if (f.querySelector('[data-arp-loading]')) return;   // sudah ditangani atribut
        if (window.arpShowLoader) window.arpShowLoader('Memproses...');
    });
</script>

<?php include "../includes/footer.php"; ?>