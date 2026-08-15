<?php
// config/koneksi.php

date_default_timezone_set('Asia/Jakarta');

// ================== BASE URL OTOMATIS (ABSOLUT DARI ROOT WEB) ==================
// Dihitung sekali di sini supaya SEMUA halaman (admin/, it/, direksi/, ahlik3/, dst)
// dan AJAX (termasuk includes/topbar.php) selalu dapat $base_url yang sama persis,
// tidak peduli halaman itu dipanggil dari folder mana / kedalaman berapa.
if (!isset($GLOBALS['base_url'])) {
    $root_fs   = dirname(__DIR__); // .../web_aksara/config -> naik satu level = root project
    $doc_root  = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
    $root_real = str_replace('\\', '/', realpath($root_fs));

    $base_path = str_replace($doc_root, '', $root_real);
    $base_path = '/' . trim($base_path, '/') . '/';

    $base_url = $base_path;
    $GLOBALS['base_url'] = $base_url;
}

// Database configurations
$host = "localhost";
$username = "root";
$password = "";
$database = "web_aksara";

try {
    $conn = new PDO("mysql:host=$host;dbname=$database;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

// ================== TABEL UNIT PENGAJUAN (1 pengajuan bisa banyak unit) ==================
// Data_Klien dulunya hanya bisa mengajukan 1 unit per pengajuan (lewat kolom
// klasifikasi_objek_k3 & jenis_objek di Pengajuan_Pemeriksaan). Sekarang 1 perusahaan
// bisa mengajukan BANYAK unit sekaligus, jadi setiap unit disimpan sebagai baris
// tersendiri di sini, terhubung ke Pengajuan_Pemeriksaan (pengajuan_id) dan ke
// jenis_objek_k3 (id_jenis) supaya Bidang-nya (kategori_objek_k3) bisa diambil lewat JOIN,
// bukan disalin manual. Dibuat otomatis (IF NOT EXISTS) agar tidak perlu migrasi manual.
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS Pengajuan_Pemeriksaan_Unit (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            pengajuan_id    INT NOT NULL,
            id_jenis        INT NULL,
            nama_unit       VARCHAR(255) NOT NULL,
            urutan          INT NOT NULL DEFAULT 0,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pengajuanunit_pengajuan FOREIGN KEY (pengajuan_id)
                REFERENCES Pengajuan_Pemeriksaan(id) ON DELETE CASCADE,
            CONSTRAINT fk_pengajuanunit_jenis FOREIGN KEY (id_jenis)
                REFERENCES jenis_objek_k3(id_jenis)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    // Kalau gagal dibuat otomatis (mis. hak akses DB terbatas), biarkan saja;
    // halaman yang memakainya sudah punya fallback ke kolom lama.
}

// ================== TAMBAH KOLOM jenis_pemeriksaan PER UNIT ==================
// Supaya tiap unit bisa punya jenis pemeriksaan sendiri (mis. unit A "Baru", unit B "Berkala"),
// bukan cuma 1 jenis pemeriksaan untuk seluruh pengajuan.
try {
    $cek = $conn->query("
        SELECT COUNT(*) AS jml FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'Pengajuan_Pemeriksaan_Unit'
          AND COLUMN_NAME = 'jenis_pemeriksaan'
    ")->fetch();
    if ((int) $cek['jml'] === 0) {
        $conn->exec("
            ALTER TABLE Pengajuan_Pemeriksaan_Unit
            ADD COLUMN jenis_pemeriksaan ENUM(
                'Pemeriksaan Baru','Pemeriksaan Berkala','Pemeriksaan Ulang','Pemeriksaan Khusus'
            ) NULL AFTER id_jenis
        ");
    }
} catch (PDOException $e) {
    // Biarkan saja kalau gagal; halaman yang memakainya sudah fallback ke kolom lama.
}

// ================== AUDIT LOG ==================
// Menyediakan fungsi catatAudit() ke semua file yang sudah require_once file ini,
// sekaligus membuat tabel Audit_Log otomatis kalau belum ada.
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/notifikasi_helper.php';
require_once __DIR__ . '/../includes/mail_helper.php'; 
?>