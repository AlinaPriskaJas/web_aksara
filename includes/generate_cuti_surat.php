<?php
// includes/generate_cuti_surat.php
//
// Mengisi template storage/templates/permohonan-cuti-dan-pengalihan-tugas.docx
// dengan data satu pengajuan Cuti Tahunan (tabel Cuti + Cuti_Serah_Terima),
// lalu mengirimkannya sebagai file .docx (default) atau .pdf (?format=pdf)
// untuk diunduh/dicetak. Dipanggil dari tombol "Cetak Surat Cuti" di
// admin/it/ahlik3/direksi/cuti.php (hanya muncul saat status = Disetujui).
//
// Surat yang sudah Disetujui SEHARUSNYA sudah otomatis diunggah ke Drive & linknya
// tersimpan di Cuti.surat_cuti_link begitu direksi menyetujui pengajuan (lihat
// direksi/approval.php + includes/cuti_surat_helper.php). Kalau untuk suatu sebab
// link itu masih kosong (mis. data lama sebelum fitur ini ada, atau upload waktu
// approval sempat gagal), file ini otomatis mencoba mengunggahnya ke Drive juga
// (self-heal) sebelum surat dikirim ke browser -- supaya "surat wajib ada di
// Drive" tetap terpenuhi lewat jalur mana pun surat itu pernah dibuat.
//
// Pembuatan dokumen (isi macro & tabel uraian tugas) sekarang ada di
// includes/cuti_surat_helper.php (arp_generate_surat_cuti_docx), dipakai bersama
// oleh file ini dan direksi/approval.php supaya isinya selalu konsisten.

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/drive_helper.php';
require_once __DIR__ . '/cuti_surat_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];
$current_role = $_SESSION['role'] ?? '';
$cuti_id = (int) ($_GET['id'] ?? 0);
$format = ($_GET['format'] ?? 'docx') === 'pdf' ? 'pdf' : 'docx';

if ($cuti_id <= 0) {
    http_response_code(400);
    exit('ID pengajuan cuti tidak valid.');
}

// ================== CEK HAK AKSES ==================
try {
    $stmtCheck = $conn->prepare("SELECT user_id, status FROM Cuti WHERE id = :id LIMIT 1");
    $stmtCheck->execute(['id' => $cuti_id]);
    $cutiCheck = $stmtCheck->fetch();
} catch (PDOException $e) {
    http_response_code(500);
    exit('Gagal mengambil data cuti: ' . $e->getMessage());
}

if (!$cutiCheck) {
    http_response_code(404);
    exit('Pengajuan cuti tidak ditemukan.');
}

// Role admin/it/direksi boleh mencetak surat siapa saja; role lain hanya miliknya sendiri.
$rolePengelola = ['admin', 'it', 'direksi'];
if (!in_array($current_role, $rolePengelola, true) && (int) $cutiCheck['user_id'] !== $current_user_id) {
    http_response_code(403);
    exit('Anda tidak berhak mencetak surat cuti ini.');
}

// ================== BANGUN DOKUMEN ==================
// Kalau pengajuannya masih "Menunggu" dan yang buka adalah direksi/admin/it
// (bukan si pemohon sendiri), anggap ini PRATINJAU -- supaya direksi bisa
// baca isi Surat Cuti & Pengalihan Tugas dulu sebelum memutuskan approve/
// reject. Kalau statusnya sudah Disetujui, tetap jalur normal (surat resmi).
$sudahDisetujui = ($cutiCheck['status'] ?? null) === 'Disetujui';
// Pemohon sendiri tetap harus nunggu Disetujui (sama seperti sebelumnya --
// tombol "Cetak Surat Cuti" di cuti.php memang cuma muncul saat Disetujui).
// Reviewer (admin/it/direksi) boleh pratinjau kapan pun, termasuk Menunggu.
$wajibDisetujui = $sudahDisetujui ? true : !in_array($current_role, $rolePengelola, true);

try {
    $hasil = arp_generate_surat_cuti_docx($conn, $cuti_id, $wajibDisetujui);
} catch (RuntimeException $e) {
    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES));
}

$docxPath = $hasil['docx_path'];
$namaFileDasar = $hasil['nama_file_dasar'];

// ================== SELF-HEAL: PASTIKAN SURAT ADA DI DRIVE ==================
// Kalau surat_cuti_link masih kosong (belum pernah diunggah otomatis waktu
// approval), unggah salinan dari sini juga supaya tetap tersimpan di Drive.
// Ini best-effort: kalau gagal (mis. Drive lagi bermasalah), unduhan surat ke
// user TETAP dilanjutkan -- jangan sampai user gagal mencetak surat gara-gara
// masalah Drive.
// ================== SELF-HEAL: PASTIKAN SURAT ADA DI DRIVE ==================
try {
    if ($sudahDisetujui && !arp_ambil_link_surat_cuti($conn, $cuti_id)) {
        arp_generate_dan_unggah_surat_cuti($conn, $cuti_id, true);
        // Gagal upload? Biarkan saja -- tetap bisa dicoba lagi lewat tombol ini
        // atau lewat proses approval kalau suratnya belum sempat dibuat sama sekali.
    }
} catch (Throwable $e) {
    error_log('Self-heal Surat Cuti gagal untuk Cuti #' . $cuti_id . ': ' . $e->getMessage());
}

// ================== KIRIM FILE KE BROWSER ==================
if ($format === 'pdf') {
    $pdfPath = convertDocxToPdf($docxPath);
    if ($pdfPath) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $namaFileDasar . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        @unlink($pdfPath);
        @unlink($docxPath);
        exit;
    }
    // Konversi PDF gagal (mis. LibreOffice tidak tersedia di server) -> jatuhkan ke .docx
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $namaFileDasar . '.docx"');
header('Content-Length: ' . filesize($docxPath));
readfile($docxPath);
@unlink($docxPath);
exit;