<?php
// includes/audit_helper.php
//
// Helper terpusat untuk mencatat jejak audit (siapa, ngapain, kapan) ke tabel
// Audit_Log. Tabel ini sudah dibaca oleh it/audit.php, tapi sebelumnya belum
// ada satupun modul yang menulis ke situ — file ini yang mengisinya.
//
// Cara pakai (1 baris, taruh SETELAH query INSERT/UPDATE/DELETE berhasil):
//
//   catatAudit($conn, 'Cuti', 'Tambah', 'Mengajukan cuti tgl 10-12 Agustus');
//
//   // dengan data sebelum/sesudah (array otomatis di-JSON-kan):
//   catatAudit($conn, 'User', 'Ubah', 'Ubah data user #5', $dataLama, $dataBaru);
//
//   // override user_id manual (default ambil dari session yang sedang login):
//   catatAudit($conn, 'Reimburse', 'Setujui', 'Approve reimburse #12', null, null, $approverId);

if (!function_exists('catatAudit')) {
    function catatAudit(
        PDO $conn,
        string $modul,
        string $aksi,
        string $detail = '',
        $data_sebelum = null,
        $data_sesudah = null,
        ?int $user_id = null
    ): bool {
        try {
            if ($user_id === null) {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
            }

            // Kalau tidak ada user yang bisa diidentifikasi, jangan paksa insert
            // (kolom user_id NOT NULL + FK ke Users), cukup log ke error_log saja.
            if ($user_id <= 0) {
                error_log("catatAudit: dilewati, user_id tidak valid. Modul={$modul}, Aksi={$aksi}");
                return false;
            }

            $sebelumJson = is_array($data_sebelum) || is_object($data_sebelum)
                ? json_encode($data_sebelum, JSON_UNESCAPED_UNICODE)
                : $data_sebelum;

            $sesudahJson = is_array($data_sesudah) || is_object($data_sesudah)
                ? json_encode($data_sesudah, JSON_UNESCAPED_UNICODE)
                : $data_sesudah;

            $stmt = $conn->prepare("
                INSERT INTO Audit_Log (user_id, modul, aksi, detail_perubahan, data_sebelum, data_sesudah, waktu_kejadian)
                VALUES (:user_id, :modul, :aksi, :detail, :sebelum, :sesudah, NOW())
            ");
            $stmt->execute([
                'user_id' => $user_id,
                'modul'   => $modul,
                'aksi'    => $aksi,
                'detail'  => $detail,
                'sebelum' => $sebelumJson,
                'sesudah' => $sesudahJson,
            ]);

            return true;
        } catch (Throwable $e) {
            // Pencatatan audit TIDAK BOLEH menggagalkan proses utama (simpan cuti,
            // hapus user, dll). Kalau gagal, cukup catat ke error_log server.
            error_log("catatAudit gagal: " . $e->getMessage());
            return false;
        }
    }
}

// Pastikan tabel Audit_Log tersedia. Dibuat otomatis (IF NOT EXISTS) mengikuti
// pola auto-migrasi yang sudah dipakai di config/koneksi.php, supaya tidak
// perlu migrasi manual di server produksi.
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS Audit_Log (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            user_id           INT NOT NULL,
            modul             VARCHAR(50) NOT NULL,
            aksi              VARCHAR(50) NOT NULL,
            detail_perubahan  TEXT NULL,
            data_sebelum      TEXT NULL,
            data_sesudah      TEXT NULL,
            waktu_kejadian    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_auditlog_user FOREIGN KEY (user_id)
                REFERENCES Users(id) ON DELETE CASCADE,
            INDEX idx_auditlog_modul (modul),
            INDEX idx_auditlog_waktu (waktu_kejadian)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    // Biarkan saja kalau gagal dibuat otomatis (mis. hak akses DB terbatas);
    // catatAudit() di atas sudah dibungkus try/catch jadi tidak akan fatal.
}
