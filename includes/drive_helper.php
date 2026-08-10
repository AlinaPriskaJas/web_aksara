<?php
// includes/drive_helper.php
//
// Helper untuk upload file ke Google Drive lewat Google Apps Script Web App.

/**
 * Upload satu file lokal ke Google Drive lewat Apps Script Web App.
 *
 * @param string $path_file_lokal Path file sementara di server (mis. dari $_FILES[...]['tmp_name'])
 * @param string $nama_file       Nama file yang akan tersimpan di Drive
 * @param string $mime_type       MIME type file (mis. application/pdf, image/jpeg)
 * @param int    $pengajuan_id    Opsional, untuk keperluan log di sisi Apps Script (ID record terkait)
 * @param string $kategori        Nama subfolder tujuan di Drive (mis. 'Absensi', 'Cuti', 'Reimburse').
 *                                 Subfolder dibuat otomatis oleh Apps Script kalau belum ada.
 *
 * @return array|null ['file_id' => string, 'link' => string] kalau sukses, null kalau gagal.
 */
function arp_upload_ke_drive(string $path_file_lokal, string $nama_file, string $mime_type, int $pengajuan_id = 0, string $kategori = 'Lainnya'): ?array
{
    $config_path = __DIR__ . '/../config/drive_config.php';

    if (!file_exists($config_path)) {
        error_log('Drive upload gagal: config/drive_config.php belum dibuat. Copy dari drive_config.example.php lalu isi kredensialnya.');
        return null;
    }

    $config = require $config_path;

    if (empty($config['webapp_url']) || strpos($config['webapp_url'], 'GANTI_DENGAN') !== false
        || empty($config['secret_token']) || strpos($config['secret_token'], 'GANTI_DENGAN') !== false
        || strpos($config['webapp_url'], 'ISI_') !== false || strpos($config['secret_token'], 'ISI_') !== false) {
        error_log('Drive upload gagal: config/drive_config.php belum diisi dengan kredensial asli.');
        return null;
    }

    if (!file_exists($path_file_lokal)) {
        error_log('Drive upload gagal: file lokal tidak ditemukan - ' . $path_file_lokal);
        return null;
    }

    $base64 = base64_encode(file_get_contents($path_file_lokal));

    $ch = curl_init($config['webapp_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // wajib: Apps Script selalu redirect ke googleusercontent.com
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'token'        => $config['secret_token'],
        'filename'     => $nama_file,
        'mimetype'     => $mime_type,
        'filedata'     => $base64,
        'pengajuan_id' => $pengajuan_id,
        'kategori'     => $kategori,
    ]));

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log('Drive upload gagal (cURL error): ' . $curl_error);
        return null;
    }

    $data = json_decode($response, true);

    if (!$data || empty($data['success'])) {
        error_log('Drive upload gagal (HTTP ' . $http_code . '): ' . substr((string) $response, 0, 500));
        return null;
    }

    return [
        'file_id' => $data['file_id'] ?? null,
        'link'    => $data['link'] ?? null,
    ];
}