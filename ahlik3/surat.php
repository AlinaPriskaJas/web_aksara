<?php
// ahlik3/surat.php — Modul Persuratan untuk Ahli K3 (tab Surat & Buat Surat saja)
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ahli_k3') {
    header("Location: ../login.php");
    exit;
}

// Alias supaya kode di bawah (hasil adaptasi) tetap konsisten memakai $pdo.
$pdo = $conn;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once "../includes/functions.php";

$page_title = "Manajemen Surat";
$current_user_id = $_SESSION['user_id'];

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
$tabMap = ['surat' => 'tabPanelSurat', 'masuk' => 'tabPanelSuratMasuk', 'buat' => 'tabPanelBuatSurat'];
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
// [TAB: BUAT SURAT] GENERATE SURAT DARI TEMPLATE
// ==========================================
$errorGenerateSurat = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'generate_surat') {
    $active_tab = 'tabPanelBuatSurat';
    $isPreviewOnly = ($_POST['preview_only'] ?? '') === '1';

    if (!$isPreviewOnly) {
        $kodeIdPost = (int) ($_POST['kode_id'] ?? 0);
        $templateIdPost = (int) ($_POST['template_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("SELECT k.*, t.id AS template_id, t.file_path, t.format
                                    FROM kode_surat k
                                    JOIN kode_template kt ON kt.kode_id = k.id AND kt.template_id = ?
                                    JOIN template_master t ON t.id = kt.template_id
                                    WHERE k.id = ?");
            $stmt->execute([$templateIdPost, $kodeIdPost]);
            $kode = $stmt->fetch();

            if (!$kode || !$kode['file_path']) {
                throw new RuntimeException("Kombinasi jenis surat & template ini tidak/belum terhubung.");
            }
            if (!is_file(BASE_PATH . '/' . $kode['file_path'])) {
                throw new RuntimeException("File template master tidak ditemukan di storage. Silakan hubungi Admin untuk mengupload ulang template ini.");
            }
            if ($kode['format'] !== 'word_pdf') {
                throw new RuntimeException("Template ini bukan file Word (.docx), tidak bisa digenerate otomatis lewat form ini.");
            }

            $pdo->beginTransaction();

            $noUrutManualPost = trim($_POST['no_urut_manual'] ?? '');
            $nomorSurat = resolveNomorSurat($pdo, $kodeIdPost, $noUrutManualPost);
            $nomorAgenda = generateNomorAgenda($pdo, 'Keluar');

            $dataForm = [];
            foreach ($_POST['dinamis'] ?? [] as $fieldName => $fieldValue) {
                $fieldValue = trim((string) $fieldValue);
                if (preg_match('/tanggal|tgl/i', $fieldName) && $fieldValue !== '') {
                    $fieldValue = formatTanggalIndonesia($fieldValue);
                }
                $dataForm[$fieldName] = $fieldValue;
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

            $fileHasilRelatif = generateSuratDocx(BASE_PATH . '/' . $kode['file_path'], $dataForm, $items, $nomorSurat, $blocksData, $kode['nama']);

            $perihalDariWord = extractPerihalFromDocxText(BASE_PATH . '/' . $fileHasilRelatif);
            $perihalSimpan = $perihalDariWord
                ?? $dataForm['perihal']
                ?? ($_POST['perihal'] ?? null)
                ?? ($kode['nama'] ?? '-');
            $tujuanSimpan = $dataForm['instansi_tujuan']
                ?? $dataForm['tujuan']
                ?? $dataForm['nama_perusahaan']
                ?? $dataForm['nama_perusahaan_tujuan']
                ?? $dataForm['item_nama_perusahaan']
                ?? '-';

            $statusInput = trim($_POST['status'] ?? '') ?: 'Draft';

            $isiDataDisimpan = $dataForm;
            if (!empty($items)) {
                $isiDataDisimpan['__items'] = $items;
            }
            if (!empty($blocksData)) {
                $isiDataDisimpan['__blok'] = $blocksData;
            }

            $insert = $pdo->prepare("INSERT INTO surat
                (nomor_agenda, nomor, kode_id, template_id, perihal, status, arah, tujuan, dibuat_oleh, tgl_dibuat, tanggal_diterima, file_hasil, isi_data)
                VALUES (?, ?, ?, ?, ?, ?, 'Keluar', ?, ?, CURDATE(), NULL, ?, ?)");
            $insert->execute([
                $nomorAgenda,
                $nomorSurat,
                $kodeIdPost,
                $templateIdPost,
                $perihalSimpan,
                $statusInput,
                $tujuanSimpan,
                $current_user_id,
                $fileHasilRelatif,
                json_encode($isiDataDisimpan, JSON_UNESCAPED_UNICODE),
            ]);

            $pdo->commit();
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

        $cek = $pdo->prepare("SELECT * FROM surat WHERE id = ? FOR UPDATE");
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

        $pdo->prepare("UPDATE surat SET status = 'Menunggu Persetujuan' WHERE id = ?")->execute([$suratId]);

        $insertApproval = $pdo->prepare("
            INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, level, status)
            VALUES ('Surat', :ref_id, :requester_id, 1, 'Menunggu')
        ");
        $insertApproval->execute([
            ':ref_id' => $suratId,
            ':requester_id' => $surat['dibuat_oleh'],
        ]);

        $pdo->commit();
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
        $cek = $pdo->prepare("SELECT status, dibuat_oleh FROM surat WHERE id = ?");
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

        $pdo->prepare("UPDATE surat SET status = 'Draft' WHERE id = ?")->execute([$suratId]);
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

        $cek = $pdo->prepare("SELECT * FROM surat WHERE id = ? FOR UPDATE");
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

        $pdo->prepare("UPDATE surat SET status = 'Terkirim' WHERE id = ?")->execute([$suratId]);

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

            // Salin berkas surat ke Dokumen Digital agar bisa dilihat/diunduh client
            if (!empty($surat['file_hasil'])) {
                $pdo->prepare("
                    INSERT INTO Dokumen_Digital (nama_dokumen, kategori, file_path, modul_sumber, ref_id, klien_id, visibilitas, diupload_oleh)
                    VALUES (?, 'Lainnya', ?, 'Surat', ?, ?, 'Client', ?)
                ")->execute([
                            $surat['nomor'] . ' - ' . $surat['perihal'],
                            $surat['file_hasil'],
                            $suratId,
                            $klienTerkirim['klien_id'],
                            $current_user_id,
                        ]);
            }
        }

        $pdo->commit();

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
        $cek = $pdo->prepare("SELECT status, dibuat_oleh FROM surat WHERE id = ?");
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

        $pdo->prepare("UPDATE surat SET status = 'Diarsipkan' WHERE id = ?")->execute([$suratId]);
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
        $stmt = $pdo->prepare("SELECT * FROM surat WHERE id = ?");
        $stmt->execute([$suratId]);
        $s = $stmt->fetch();
        if (!$s) {
            throw new RuntimeException("Surat tidak ditemukan.");
        }
        if ((int) $s['dibuat_oleh'] !== (int) $current_user_id) {
            throw new RuntimeException("Anda hanya bisa menghapus surat yang Anda buat sendiri.");
        }

        $pdo->prepare("DELETE FROM surat WHERE id = ?")->execute([$suratId]);

        if (!empty($s['file_hasil']) && is_file(BASE_PATH . '/' . $s['file_hasil'])) {
            @unlink(BASE_PATH . '/' . $s['file_hasil']);
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

// ==========================================
// [DATA: TAB SURAT] Daftar surat (pencarian & pagination dilakukan di sisi
// klien lewat handleTableSearch()/initTablePagination() seperti modul lain)
// ==========================================
// Semua surat KELUAR bisa dilihat (read-only) oleh siapa saja di sini,
// tapi aksi (ajukan/revisi/kirim/arsipkan/hapus) hanya boleh dilakukan oleh
// pembuat surat itu sendiri -- dicek lagi di kolom Tindakan/Aksi & di setiap
// handler POST di atas.
$daftar_surat = $pdo->query("
    SELECT s.*, k.kode AS kode_str, k.nama AS jenis_surat_kode,
           u.nama_lengkap AS pembuat_nama, u.role AS pembuat_role
    FROM surat s
    JOIN kode_surat k ON s.kode_id = k.id
    LEFT JOIN Users u ON s.dibuat_oleh = u.id
    WHERE s.arah = 'Keluar'
    ORDER BY s.tgl_dibuat DESC, s.id DESC
")->fetchAll();

// Surat MASUK hanya dicatat oleh Admin. Di sini sifatnya referensi bacaan
// saja untuk semua role -- tidak ada tombol "Catat Surat Masuk", tidak ada
// aksi ubah status, dan tidak ada tombol Hapus.
$daftar_surat_masuk = $pdo->query("
    SELECT s.*, k.kode AS kode_str, k.nama AS jenis_surat_kode
    FROM surat s
    JOIN kode_surat k ON s.kode_id = k.id
    WHERE s.arah = 'Masuk'
    ORDER BY s.tgl_dibuat DESC, s.id DESC
")->fetchAll();

// ==========================================
// [DATA: TAB BUAT SURAT] Daftar kode surat (yang punya minimal 1 template)
// ==========================================
$daftar_kode = $pdo->query("SELECT * FROM kode_surat ORDER BY nama")->fetchAll();

$template_per_kode = [];
$rowsTplPerKode = $pdo->query("SELECT kt.id AS kode_template_id, kt.kode_id, kt.template_id, kt.is_default,
                                       t.nama AS nama_template, t.deskripsi, t.format
                                FROM kode_template kt
                                JOIN template_master t ON t.id = kt.template_id
                                ORDER BY kt.is_default DESC, t.nama ASC")->fetchAll();
foreach ($rowsTplPerKode as $r) {
    $template_per_kode[$r['kode_id']][] = $r;
}
$daftar_kode_dengan_template = array_values(array_filter(
    $daftar_kode,
    fn($k) => !empty($template_per_kode[$k['id']])
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
if ($active_tab === 'tabPanelBuatSurat' && $kodeIdTerpilih && $templateIdTerpilih) {
    $stmt = $pdo->prepare("SELECT k.*, t.id AS template_id, t.nama AS nama_template, t.file_path, t.format, t.fields_json
                            FROM kode_surat k
                            JOIN kode_template kt ON kt.kode_id = k.id AND kt.template_id = ?
                            JOIN template_master t ON t.id = kt.template_id
                            WHERE k.id = ?");
    $stmt->execute([$templateIdTerpilih, $kodeIdTerpilih]);
    $kodeTerpilih = $stmt->fetch();

    if ($kodeTerpilih && !empty($kodeTerpilih['fields_json'])) {
        $decoded = json_decode($kodeTerpilih['fields_json'], true) ?: [];
        if (isset($decoded['fields']) || isset($decoded['table_fields'])) {
            $fields_dinamis = $decoded['fields'] ?? [];
            $fields_tabel = $decoded['table_fields'] ?? [];
            $fields_blok = $decoded['blocks'] ?? [];
        } else {
            $fields_dinamis = $decoded;
        }

        if (defined('FIELD_OTOMATIS_SISTEM')) {
            $fields_dinamis = array_values(array_filter(
                $fields_dinamis,
                fn($f) => !in_array(strtolower($f['field'] ?? ''), FIELD_OTOMATIS_SISTEM, true)
            ));
        }
    }
}

$file_template_hilang = $kodeTerpilih && !is_file(BASE_PATH . '/' . $kodeTerpilih['file_path']);

$auto_fields_template = ($kodeTerpilih && !$file_template_hilang && $kodeTerpilih['format'] === 'word_pdf')
    ? scanAutoFieldsFromDocx(BASE_PATH . '/' . $kodeTerpilih['file_path'])
    : [];
$ada_total = in_array('total', $auto_fields_template, true);
$ada_ppn = in_array('ppn', $auto_fields_template, true);
$ada_pph23 = in_array('pph_23', $auto_fields_template, true);
$ada_total_bayar = in_array('total_bayar', $auto_fields_template, true);
$ada_ringkasan_total = $ada_total || $ada_ppn || $ada_pph23 || $ada_total_bayar;

$nilai_dinamis = [];
foreach ($fields_dinamis as $f) {
    $nilai_dinamis[$f['field']] = $_POST['dinamis'][$f['field']] ?? '';
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

    // Ikut cek nomor urut TERTINGGI yang benar-benar sudah dipakai di tabel
    // surat untuk kode ini di tahun berjalan (termasuk yang diisi manual),
    // supaya prediksi nomor berikutnya tidak "mundur"/nabrak nomor lama.
    $stmtMaxNomor = $pdo->prepare("
        SELECT nomor FROM surat
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
                data-tab-target="tabPanelSurat" onclick="switchTab('tabPanelSurat', this)">
                <i class="bi bi-envelope-paper me-1"></i> Surat
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelSuratMasuk' ? ' active' : '' ?>"
                data-tab-target="tabPanelSuratMasuk" onclick="switchTab('tabPanelSuratMasuk', this)">
                <i class="bi bi-inbox me-1"></i> Surat Masuk
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelBuatSurat' ? ' active' : '' ?>"
                data-tab-target="tabPanelBuatSurat" onclick="switchTab('tabPanelBuatSurat', this)">
                <i class="bi bi-file-earmark-plus me-1"></i> Buat Surat
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
                                        <?php if (!empty($s['nomor_agenda'])): ?>
                                            <br><small class="text-secondary"><?= e($s['nomor_agenda']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($s['jenis_surat_kode']) ?> <span
                                            class="text-secondary">(<?= e($s['kode_str']) ?>)</span></td>
                                    <td style="white-space:normal; word-break:break-word; max-width:280px;"><?= e($s['perihal']) ?></td>
                                    <td style="white-space:normal; word-break:break-word; max-width:200px;"><?= e($s['tujuan']) ?></td>
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
                                    <td><span class="<?= badgeStatus($s['status'] ?? '') ?>"><?= e($s['status']) ?></span>
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
                                                    <form method="POST" action="surat.php" class="d-inline"
                                                        onsubmit="return confirm('Kembalikan surat ini ke Draft untuk direvisi?');">
                                                        <input type="hidden" name="aksi" value="revisi_surat">
                                                        <input type="hidden" name="surat_id" value="<?= (int) $s['id'] ?>">
                                                        <button type="submit" class="btn-secondary-custom"
                                                            style="height:28px; padding:0 10px; font-size:0.75rem;">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Revisi
                                                        </button>
                                                    </form>
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
                                                <?php if ($suratMilikSaya): ?>
                                                    <a class="btn btn-outline-primary btn-sm py-1" style="font-size:0.75rem;"
                                                        href="edit_surat.php?id=<?= (int) $s['id'] ?>" title="Edit Surat">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                        href="../<?= e($s['file_hasil']) ?>" target="_blank" title="Lihat berkas">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="../<?= e($s['file_hasil']) ?>" download title="Unduh">
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
                                        <?php if (!empty($s['nomor_agenda'])): ?>
                                            <br><small class="text-secondary"><?= e($s['nomor_agenda']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($s['jenis_surat_kode']) ?></td>
                                    <td style="white-space:normal; word-break:break-word; max-width:280px;"><?= e($s['perihal']) ?></td>
                                    <td style="white-space:normal; word-break:break-word; max-width:200px;"><?= e($s['tujuan']) ?></td>
                                    <td><?= !empty($s['tgl_dibuat']) ? date('d-m-Y', strtotime($s['tgl_dibuat'])) : '-' ?>
                                    </td>
                                    <td><span class="<?= badgeStatus($s['status'] ?? '') ?>"><?= e($s['status']) ?></span>
                                    </td>
                                    <td class="col-aksi" style="text-align:center;">
                                        <div class="table-actions">
                                            <?php if (!empty($s['file_hasil'])): ?>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="../<?= e($s['file_hasil']) ?>" target="_blank"
                                                    title="Lihat / unduh berkas">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                    href="../<?= e($s['file_hasil']) ?>" download title="Unduh">
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
                                window.location.href = url;
                            }

                            selectJenis.addEventListener('change', function () {
                                var kodeId = this.value;
                                if (!kodeId) {
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
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">No Urut Surat</label>
                                    <div class="nomor-surat-group">
                                        <input type="text" name="no_urut_manual"
                                            class="form-control-custom nomor-surat-input"
                                            value="<?= e($_POST['no_urut_manual'] ?? sprintf('%03d', $counterPreview)) ?>"
                                            placeholder="<?= e(sprintf('%03d', $counterPreview)) ?>">
                                        <div class="form-control-custom field-readonly text-secondary nomor-surat-suffix">
                                            /<?= e($kodeTerpilih['kode']) ?>/ARP/<?= e($bulanRomawi) ?>/<?= e($tahun) ?>
                                        </div>
                                    </div>
                                    <!-- <small class="text-secondary text-xs d-block mt-1">Otomatis:
                                        <b><?= e(sprintf('%03d', $counterPreview)) ?></b> — kode jenis/ARP/bulan/tahun
                                        tetap otomatis, hanya angka urut yang bisa Anda ganti.</small> -->
                                </div>
                            </div>

                            <?php if (!empty($kodeTerpilih['deskripsi'])): ?>
                                <div class="alert alert-success-custom text-xs mb-3">
                                    <i class="bi bi-info-circle-fill"></i>
                                    <div><span class="fw-semibold">Deskripsi:</span> <?= nl2br(e($kodeTerpilih['deskripsi'])) ?>
                                    </div>
                                </div>
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
                                        <p class="text-secondary text-xs mt-2 mb-0">Subtotal, Total, PPN &amp; PPH
                                            dihitung otomatis oleh sistem saat surat disimpan.</p>
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

                            <?php if ($ada_ringkasan_total): ?>
                                <div id="blok-ringkasan-total" data-ada-pph23="<?= $ada_pph23 ? '1' : '0' ?>"
                                    data-ada-ppn="<?= $ada_ppn ? '1' : '0' ?>">
                                    <?php if ($ada_total): ?>
                                        <div class="ringkasan-total-row">
                                            <span>Total</span><span id="preview-total" style="font-family:monospace;">Rp. 0</span>
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
                                    <?php if ($ada_total_bayar): ?>
                                        <div class="ringkasan-total-row total-bayar">
                                            <span>Total Bayar</span><span id="preview-total-bayar" style="font-family:monospace;">Rp.
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
                                            var elPph = document.getElementById('preview-pph');

                                            if (!adaSubtotal) {
                                                elTotal.textContent = 'Rp. 0';
                                                elPpn.textContent = 'Rp. 0';
                                                if (elPph) elPph.textContent = 'Rp. 0';
                                                elTotalBayar.textContent = 'Rp. 0';
                                                return;
                                            }

                                            var ppn = Math.round(totalSemuaBaris * 0.11);
                                            var pph = adaPph23 ? Math.round(totalSemuaBaris * 0.02) : 0;
                                            var totalBayar = totalSemuaBaris + ppn - pph;

                                            elTotal.textContent = formatRupiahJs(totalSemuaBaris);
                                            elPpn.textContent = formatRupiahJs(ppn);
                                            if (adaPph23 && elPph) elPph.textContent = formatRupiahJs(pph);
                                            elTotalBayar.textContent = formatRupiahJs(totalBayar);
                                        };

                                        var observer = new MutationObserver(window.hitungUlangTotalSurat);
                                        observer.observe(tbody, { childList: true });

                                        window.hitungUlangTotalSurat();
                                    })();
                                </script>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
        </div>

    </div>

</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelSurat', 10);
        initTablePagination('tabelSuratMasuk', 10);
    });
</script>

<?php if ($errorGenerateSurat): ?>
    <script>document.addEventListener('DOMContentLoaded', function () { switchTab('tabPanelBuatSurat', document.querySelector('[data-tab-target=tabPanelBuatSurat]')); });</script>
<?php endif; ?>

<?php include "../includes/footer.php"; ?>