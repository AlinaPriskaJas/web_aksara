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



if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once "../includes/functions.php";
require_once "../includes/drive_helper.php";

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

            // Kalau template pakai ${no_surat} (format khusus JD+kode/bulan/tahun),
            // pakai format itu SEBAGAI nomor surat -- jangan generate nomor ARP
            // biasa juga, supaya tidak ada dua sistem penomoran berjalan sekaligus.
            $adaNoSuratKhususPost = in_array('no_surat', scanAutoFieldsFromDocx(BASE_PATH . '/' . $kode['file_path']), true);

            if ($adaNoSuratKhususPost) {
                $noSuratManualPost = trim($_POST['no_surat_manual'] ?? '');
                $nomorSurat = buatNoSuratKhusus($noSuratManualPost);
                $cekNomorDup = $pdo->prepare("SELECT id FROM surat WHERE nomor = ?");
                $cekNomorDup->execute([$nomorSurat]);
                if ($cekNomorDup->fetch()) {
                    throw new RuntimeException("Nomor surat \"{$nomorSurat}\" sudah digunakan surat lain.");
                }
            } else {
                $noUrutManualPost = trim($_POST['no_urut_manual'] ?? '');
                $nomorSurat = resolveNomorSurat($pdo, $kodeIdPost, $noUrutManualPost);
            }
            $nomorAgenda = generateNomorAgenda($pdo, 'Keluar');

            $dataForm = [];
            foreach ($_POST['dinamis'] ?? [] as $fieldName => $fieldValue) {
                $fieldValue = trim((string) $fieldValue);
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

            $ringkasanDisertakan = [
                'ppn' => isset($_POST['sertakan_ppn']),
                'pph_23' => isset($_POST['sertakan_pph23']),
                'diskon' => isset($_POST['sertakan_diskon']),
                'grand_total' => isset($_POST['sertakan_grand_total']),
                'dp' => isset($_POST['sertakan_dp']),
                'total_bayar' => isset($_POST['sertakan_total_bayar']),   // ⬅ BARU
                'sisa_pelunasan' => isset($_POST['sertakan_sisa_pelunasan']),   // ⬅ BARU
            ];


            $fileHasilRelatif = generateSuratDocx(BASE_PATH . '/' . $kode['file_path'], $dataForm, $items, $nomorSurat, $blocksData, $kode['nama'], null, $ringkasanDisertakan);

            $hasilDriveKeluar = arp_upload_ke_drive(
                BASE_PATH . '/' . $fileHasilRelatif,
                basename($fileHasilRelatif),
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                0,
                'Surat_Keluar'
            );
            $fileHasilTersimpan = ($hasilDriveKeluar && !empty($hasilDriveKeluar['link']))
                ? $hasilDriveKeluar['link']
                : $fileHasilRelatif;

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
            $isiDataDisimpan['__ringkasan'] = $ringkasanDisertakan;


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
                $fileHasilTersimpan,
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


// Satu FAMILY (surat asli + semua revisinya, nomor surat sama) selalu
// ditampilkan BERDEKATAN: family diurutkan berdasarkan tanggal surat ASLI-nya,
// lalu di dalam satu family diurutkan dari revisi TERBARU ke yang lebih lama
// (revisi terbaru di atas, surat asli paling bawah di kelompoknya).
$daftar_surat = $pdo->query("
    SELECT s.*, k.kode AS kode_str, k.nama AS jenis_surat_kode,
           u.nama_lengkap AS pembuat_nama, u.role AS pembuat_role,
           COALESCE(s.induk_surat_id, s.id) AS root_id,
           COALESCE(rootS.tgl_dibuat, s.tgl_dibuat) AS root_tgl_dibuat,
           (SELECT COUNT(*) FROM surat child WHERE child.direvisi_dari_id = s.id) AS jumlah_revisi_turunan,
           (SELECT MAX(child.revisi_ke) FROM surat child WHERE child.direvisi_dari_id = s.id) AS revisi_terbaru_ke,
           (SELECT ap.catatan FROM Approval ap
              WHERE ap.jenis_pengajuan = 'Surat' AND ap.ref_id = s.id AND ap.status = 'Ditolak'
              ORDER BY ap.tgl_aksi DESC LIMIT 1) AS catatan_ditolak
    FROM surat s
    JOIN kode_surat k ON s.kode_id = k.id
    LEFT JOIN Users u ON s.dibuat_oleh = u.id
    LEFT JOIN surat rootS ON rootS.id = COALESCE(s.induk_surat_id, s.id)
    WHERE s.arah = 'Keluar'
    ORDER BY root_tgl_dibuat DESC, root_id DESC, s.revisi_ke DESC
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

    if ($kodeTerpilih) {
        $hasilFields = muatFieldsTemplateLive($pdo, $kodeTerpilih);
        $fields_dinamis = $hasilFields['fields'];
        $fields_tabel = $hasilFields['table_fields'];
        $fields_blok = $hasilFields['blocks'];

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
                                        <a class="btn btn-outline-primary btn-sm py-1" style="font-size:0.75rem;"
                                            href="edit_surat.php?id=<?= (int) $s['id'] ?>" title="Edit Surat">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                    <?php else: ?>
                                        <?php $hrefLihat = str_starts_with($s['file_hasil'], 'http') ? $s['file_hasil'] : '../' . $s['file_hasil']; ?>
                                        <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                            href="<?= e($hrefLihat) ?>" target="_blank" title="Lihat berkas">
                                            <i class="bi bi-eye"></i>
                                        </a>
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
                                                <?php if ($suratMilikSaya): ?>
                                                    <a class="btn btn-outline-primary btn-sm py-1" style="font-size:0.75rem;"
                                                        href="edit_surat.php?id=<?= (int) $s['id'] ?>" title="Edit Surat">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <?php $hrefLihat = str_starts_with($s['file_hasil'], 'http') ? $s['file_hasil'] : '../' . $s['file_hasil']; ?>
                                                    <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                        href="<?= e($hrefLihat) ?>" target="_blank" title="Lihat berkas">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php $hrefUnduh1 = str_starts_with($s['file_hasil'], 'http') ? $s['file_hasil'] : '../' . $s['file_hasil']; ?>
                                                    <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                        href="<?= e($hrefUnduh1) ?>" download title="Unduh">
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
                                                                href="<?= e($hrefMasuk) ?>" target="_blank"
                                                                title="Lihat / unduh berkas">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                            <a class="btn btn-outline-secondary btn-sm py-1" style="font-size:0.75rem;"
                                                                href="<?= e($hrefMasuk) ?>" download title="Unduh">
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
                                <?php if (!$ada_no_surat_khusus): ?>
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
                                        <!-- <small ...> -->
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

<?php include "../includes/footer.php"; ?>