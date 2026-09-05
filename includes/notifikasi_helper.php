<?php
// includes/notifikasi_helper.php

if (!isset($conn)) {
    require_once __DIR__ . '/../config/koneksi.php';
}

/**
 * Kirim notifikasi ke tabel Notifikasi.
 *
 * @param PDO    $conn
 * @param int    $user_id        Penerima notifikasi (ID user tujuan)
 * @param string $judul
 * @param string $pesan
 * @param string $modul_terkait  contoh: 'cuti', 'insiden', 'reimburse', 'absensi'
 * @param int|null $ref_id       ID baris terkait (id pengajuan cuti, id insiden, dst)
 */
function kirimNotifikasi(PDO $conn, int $user_id, string $judul, string $pesan, string $modul_terkait, ?int $ref_id = null): bool
{
    try {
        $stmt = $conn->prepare("
            INSERT INTO Notifikasi (user_id, judul, pesan, modul_terkait, ref_id)
            VALUES (:user_id, :judul, :pesan, :modul_terkait, :ref_id)
        ");
        return $stmt->execute([
            ':user_id'        => $user_id,
            ':judul'          => $judul,
            ':pesan'          => $pesan,
            ':modul_terkait'  => $modul_terkait,
            ':ref_id'         => $ref_id,
        ]);
    } catch (PDOException $e) {
        // Jangan sampai gagal kirim notifikasi menggagalkan proses utama (mis. simpan cuti)
        error_log('Gagal kirim notifikasi: ' . $e->getMessage());
        return false;
    }
}