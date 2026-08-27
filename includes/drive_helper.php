<?php
// includes/drive_helper.php
//
// Helper untuk upload file ke Google Drive lewat Google Apps Script Web App.

/**
 * Ukuran file maksimum yang diizinkan (bytes), dibedakan per jenis kategori:
 * - Foto (selfie absensi, avatar profil, foto bukti insiden): dari kamera HP,
 *   biasanya 3-12MB. Dibatasi lebih ketat karena harus cepat & sering diambil
 *   di lapangan dengan koneksi seadanya.
 * - Dokumen (surat, sertifikat, dokumen digital, reimburse, pengajuan):
 *   PDF hasil scan bisa lebih besar, jadi diberi batas lebih longgar.
 * Setelah di-base64 encode ukurannya membengkak ~33% dari ukuran asli.
 */
const ARP_DRIVE_MAX_FILESIZE_FOTO = 8 * 1024 * 1024;      // 8MB
const ARP_DRIVE_MAX_FILESIZE_DOKUMEN = 20 * 1024 * 1024;  // 20MB

/** Kategori yang dianggap "foto" (dibatasi ke ARP_DRIVE_MAX_FILESIZE_FOTO). */
const ARP_DRIVE_KATEGORI_FOTO = ['Absensi', 'Profil', 'Insiden'];

/** Ambil batas ukuran file (bytes) yang berlaku untuk suatu kategori. */
function arp_drive_batas_filesize(string $kategori): int
{
    return in_array($kategori, ARP_DRIVE_KATEGORI_FOTO, true)
        ? ARP_DRIVE_MAX_FILESIZE_FOTO
        : ARP_DRIVE_MAX_FILESIZE_DOKUMEN;
}

/** Lebar maksimum (px) hasil kompresi gambar. Cukup untuk bukti absensi/dokumen, tidak perlu resolusi kamera penuh. */
const ARP_DRIVE_LEBAR_KOMPRESI = 1280;
/** Kualitas JPEG hasil kompresi (0-100). */
const ARP_DRIVE_KUALITAS_KOMPRESI = 75;

/**
 * Kompres & resize gambar sebelum diunggah, supaya lebih cepat dikirim & tidak
 * memberatkan koneksi user. Dokumen non-gambar (PDF, dsb.) tidak disentuh.
 * Kalau ekstensi GD tidak tersedia di server atau gambarnya gagal dibaca,
 * file asli dikirim apa adanya (fallback aman, tidak menggagalkan upload).
 *
 * @return array{path: string, mime_type: string, is_temp: bool}
 */
function arp_drive_kompres_gambar(string $path_file_lokal, string $mime_type): array
{
    $asli = ['path' => $path_file_lokal, 'mime_type' => $mime_type, 'is_temp' => false];

    if (!extension_loaded('gd')) {
        return $asli;
    }

    switch (strtolower($mime_type)) {
        case 'image/jpeg':
        case 'image/jpg':
            $src = @imagecreatefromjpeg($path_file_lokal);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($path_file_lokal);
            break;
        case 'image/webp':
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path_file_lokal) : false;
            break;
        default:
            $src = false; // Bukan gambar yang didukung (mis. PDF) -> kirim apa adanya.
    }

    if (!$src) {
        return $asli;
    }

    $lebar_asli  = imagesx($src);
    $tinggi_asli = imagesy($src);

    if ($lebar_asli > ARP_DRIVE_LEBAR_KOMPRESI) {
        $tinggi_baru = (int) round($tinggi_asli * (ARP_DRIVE_LEBAR_KOMPRESI / $lebar_asli));
        $tujuan = imagecreatetruecolor(ARP_DRIVE_LEBAR_KOMPRESI, $tinggi_baru);
        // Latar putih dulu supaya PNG transparan tidak jadi hitam saat dikonversi ke JPEG.
        imagefill($tujuan, 0, 0, imagecolorallocate($tujuan, 255, 255, 255));
        imagecopyresampled($tujuan, $src, 0, 0, 0, 0, ARP_DRIVE_LEBAR_KOMPRESI, $tinggi_baru, $lebar_asli, $tinggi_asli);
        imagedestroy($src);
        $src = $tujuan;
    }

    $path_sementara = tempnam(sys_get_temp_dir(), 'arp_drive_') . '.jpg';
    $berhasil = imagejpeg($src, $path_sementara, ARP_DRIVE_KUALITAS_KOMPRESI);
    imagedestroy($src);

    if (!$berhasil || !file_exists($path_sementara)) {
        return $asli;
    }

    return ['path' => $path_sementara, 'mime_type' => 'image/jpeg', 'is_temp' => true];
}

/**
 * Bangun nama file untuk bukti foto absensi yang diunggah ke folder "Absensi" di Drive.
 * Format: TAHUN_BULAN_TANGGAL_NamaKaryawan.ekstensi
 * Bulan & tanggal otomatis diberi angka 0 di depan kalau nilainya 1-9
 * (mis. tanggal 7 Agustus 2026 atas nama Budi Santoso -> 2026_08_07_Budi_Santoso.jpg).
 */
function arp_nama_file_absensi(string $nama_karyawan, string $ekstensi): string
{
    // Bersihkan nama karyawan supaya aman jadi nama file: hanya huruf & angka yang
    // dipertahankan, selain itu (spasi, titik, dsb.) diganti underscore.
    $nama_bersih = preg_replace('/[^A-Za-z0-9]+/', '_', trim($nama_karyawan));
    $nama_bersih = trim((string) $nama_bersih, '_');
    if ($nama_bersih === '') {
        $nama_bersih = 'Karyawan';
    }

    $ekstensi = strtolower(ltrim($ekstensi, '.'));
    if ($ekstensi === '') {
        $ekstensi = 'jpg';
    }

    // date('Y_m_d') otomatis zero-pad bulan & tanggal (01-09), jadi sudah sesuai format yang diminta.
    return date('Y_m_d') . '_' . $nama_bersih . '.' . $ekstensi;
}

/** Menyimpan pesan error detail dari percobaan upload terakhir. */
function arp_drive_set_last_error(string $pesan): void
{
    $GLOBALS['__arp_drive_last_error'] = $pesan;
}

/**
 * Ambil pesan error detail dari upload terakhir yang gagal.
 * Panggil ini setelah arp_upload_ke_drive() mengembalikan null,
 * supaya bisa ditampilkan ke user (bukan cuma pesan generik).
 */
function arp_drive_last_error(): string
{
    return $GLOBALS['__arp_drive_last_error'] ?? 'Penyebab tidak diketahui.';
}

/**
 * Kirim satu kali request upload ke Apps Script Web App.
 * Dipisah dari arp_upload_ke_drive() supaya bisa dipanggil ulang (retry).
 *
 * @return array{ok: bool, data: ?array, http_code: int, curl_error: string, transient: bool}
 */
function arp_drive_kirim_request(array $config, string $base64, string $nama_file, string $mime_type, int $pengajuan_id, string $kategori, int $timeout): array
{
    $ch = curl_init($config['webapp_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // wajib: Apps Script selalu redirect ke googleusercontent.com
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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
    $errno      = curl_errno($ch);
    curl_close($ch);

    if ($curl_error) {
        // Timeout & connection error bersifat sementara -> layak di-retry.
        $transient = in_array($errno, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST, CURLE_SSL_CONNECT_ERROR], true);
        return ['ok' => false, 'data' => null, 'http_code' => $http_code, 'curl_error' => $curl_error, 'transient' => $transient];
    }

    $data = json_decode((string) $response, true);

    if (!$data || empty($data['success'])) {
        // Error 5xx dari Google (server sedang sibuk) juga layak di-retry.
        $transient = $http_code >= 500 || $http_code === 0;
        return ['ok' => false, 'data' => $data, 'http_code' => $http_code, 'curl_error' => '', 'transient' => $transient, 'raw' => substr((string) $response, 0, 500)];
    }

    return ['ok' => true, 'data' => $data, 'http_code' => $http_code, 'curl_error' => '', 'transient' => false];
}

/**
 * Upload satu file lokal ke Google Drive lewat Apps Script Web App.
 * Otomatis mencoba ulang (retry) sampai 2x kalau kegagalan bersifat sementara
 * (timeout / koneksi putus / server Google lagi sibuk), karena penyebab paling
 * umum dari upload yang "kadang bisa kadang tidak" adalah kondisi transien
 * seperti ini, bukan konfigurasi yang salah.
 *
 * @param string $path_file_lokal Path file sementara di server (mis. dari $_FILES[...]['tmp_name'])
 * @param string $nama_file       Nama file yang akan tersimpan di Drive
 * @param string $mime_type       MIME type file (mis. application/pdf, image/jpeg)
 * @param int    $pengajuan_id    Opsional, untuk keperluan log di sisi Apps Script (ID record terkait)
 * @param string $kategori        Nama subfolder tujuan di Drive (mis. 'Absensi', 'Cuti', 'Reimburse').
 *                                 Subfolder dibuat otomatis oleh Apps Script kalau belum ada.
 *
 * @return array|null ['file_id' => string, 'link' => string] kalau sukses, null kalau gagal.
 *                     Kalau null, panggil arp_drive_last_error() untuk detail penyebabnya.
 */
function arp_upload_ke_drive(string $path_file_lokal, string $nama_file, string $mime_type, int $pengajuan_id = 0, string $kategori = 'Lainnya'): ?array
{
    $config_path = __DIR__ . '/../config/drive_config.php';

    if (!file_exists($config_path)) {
        $pesan = 'config/drive_config.php belum dibuat. Copy dari drive_config.example.php lalu isi kredensialnya.';
        error_log('Drive upload gagal: ' . $pesan);
        arp_drive_set_last_error($pesan);
        return null;
    }

    $config = require $config_path;

    if (empty($config['webapp_url']) || strpos($config['webapp_url'], 'GANTI_DENGAN') !== false
        || empty($config['secret_token']) || strpos($config['secret_token'], 'GANTI_DENGAN') !== false
        || strpos($config['webapp_url'], 'ISI_') !== false || strpos($config['secret_token'], 'ISI_') !== false) {
        $pesan = 'config/drive_config.php belum diisi dengan kredensial asli.';
        error_log('Drive upload gagal: ' . $pesan);
        arp_drive_set_last_error($pesan);
        return null;
    }

    if (!file_exists($path_file_lokal)) {
        $pesan = 'File lokal tidak ditemukan - ' . $path_file_lokal;
        error_log('Drive upload gagal: ' . $pesan);
        arp_drive_set_last_error($pesan);
        return null;
    }

    $batas_filesize = arp_drive_batas_filesize($kategori);
    $ukuran_file = filesize($path_file_lokal);
    if ($ukuran_file === false || $ukuran_file > $batas_filesize) {
        $jenis = in_array($kategori, ARP_DRIVE_KATEGORI_FOTO, true) ? 'foto' : 'dokumen';
        $pesan = sprintf(
            'Ukuran file (%s) melebihi batas maksimum %s untuk %s. Kompres/perkecil dulu sebelum diunggah.',
            $ukuran_file !== false ? round($ukuran_file / 1024 / 1024, 1) . ' MB' : 'tidak diketahui',
            round($batas_filesize / 1024 / 1024) . ' MB',
            $jenis
        );
        error_log('Drive upload gagal: ' . $pesan);
        arp_drive_set_last_error($pesan);
        return null;
    }

    // Kalau ini gambar, kompres & resize dulu -> lebih kecil, lebih cepat dikirim,
    // dan lebih jarang kena timeout. Dokumen non-gambar (PDF, dll) tidak disentuh.
    $berkas_kirim   = arp_drive_kompres_gambar($path_file_lokal, $mime_type);
    $path_dikirim   = $berkas_kirim['path'];
    $mime_dikirim   = $berkas_kirim['mime_type'];
    $nama_dikirim   = $nama_file;
    if ($berkas_kirim['is_temp'] && strtolower(pathinfo($nama_file, PATHINFO_EXTENSION)) !== 'jpg') {
        $nama_dikirim = pathinfo($nama_file, PATHINFO_FILENAME) . '.jpg';
    }

    $ukuran_dikirim = filesize($path_dikirim) ?: $ukuran_file;
    $base64 = base64_encode(file_get_contents($path_dikirim));

    // Timeout menyesuaikan ukuran file (setelah kompresi): file besar butuh waktu
    // lebih lama untuk di-upload & diproses Apps Script, minimal 45 detik, maksimal
    // 120 detik (dinaikkan dari 90s supaya dokumen besar sampai 20MB tetap kebagian waktu cukup).
    $timeout = (int) min(120, max(45, 45 + ($ukuran_dikirim / 1024 / 1024) * 4));

    $percobaan_maksimum = 3; // 1x percobaan awal + 2x retry
    $hasil = null;

    try {
        for ($percobaan = 1; $percobaan <= $percobaan_maksimum; $percobaan++) {
            $hasil = arp_drive_kirim_request($config, $base64, $nama_dikirim, $mime_dikirim, $pengajuan_id, $kategori, $timeout);

            if ($hasil['ok']) {
                return [
                    'file_id' => $hasil['data']['file_id'] ?? null,
                    'link'    => $hasil['data']['link'] ?? null,
                ];
            }

            $detail = $hasil['curl_error'] !== ''
                ? 'cURL error: ' . $hasil['curl_error']
                : 'HTTP ' . $hasil['http_code'] . ': ' . ($hasil['raw'] ?? json_encode($hasil['data']));

            error_log(sprintf('Drive upload percobaan %d/%d gagal (%s)', $percobaan, $percobaan_maksimum, $detail));

            // Kalau kegagalannya bukan hal sementara (mis. token salah / ditolak Apps Script), tidak perlu diulang.
            if (!$hasil['transient'] || $percobaan === $percobaan_maksimum) {
                break;
            }

            usleep(500000 * $percobaan); // jeda 0.5s, 1s sebelum retry berikutnya
        }
    } finally {
        // Bersihkan file JPEG sementara hasil kompresi, kalau ada.
        if ($berkas_kirim['is_temp'] && file_exists($path_dikirim)) {
            @unlink($path_dikirim);
        }
    }

    $pesan_akhir = $hasil['curl_error'] !== ''
        ? 'Koneksi ke Google Drive gagal/timeout (' . $hasil['curl_error'] . '). Coba lagi beberapa saat, atau periksa koneksi internet.'
        : 'Google Drive menolak upload (HTTP ' . $hasil['http_code'] . '). Kemungkinan kuota Apps Script penuh atau token salah.';

    // --- SEMENTARA UNTUK DEBUGGING (hapus setelah masalah 404 ketemu akar penyebabnya) ---
    // Tampilkan potongan isi respons mentah langsung di pesan error, supaya tidak perlu
    // cari-cari file error_log yang lokasinya beda-beda di tiap environment (mis. Laragon).
    if (!empty($hasil['raw'])) {
        $pesan_akhir .= ' | RAW RESPONSE: ' . substr($hasil['raw'], 0, 300);
    }
    // --- AKHIR BLOK DEBUGGING SEMENTARA ---

    arp_drive_set_last_error($pesan_akhir);

    return null;
}

function arp_hapus_file_drive(string $fileId): bool
{
    $config_path = __DIR__ . '/../config/drive_config.php';
    if (!file_exists($config_path)) return false;
    $config = require $config_path;
    if (empty($config['webapp_url']) || empty($config['secret_token'])) return false;

    $ch = curl_init($config['webapp_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'token'  => $config['secret_token'],
        'action' => 'delete',
        'file_id' => $fileId,
    ]));
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode((string) $response, true);
    return !empty($data['success']);
}