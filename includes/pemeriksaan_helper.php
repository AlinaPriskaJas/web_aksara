<?php
// includes/pemeriksaan_helper.php
// Dipakai oleh ahli_k3/upload.php, ahli_k3/pemeriksaan.php, dan laporan.php.

/**
 * Dipanggil SETIAP KALI sebuah Laporan_Pemeriksaan dihapus.
 * Pemeriksaan terkait kembali ke status "belum" (tombol Buat Laporan muncul lagi
 * di tab Pemeriksaan, dan hilang dari tab Diproses/Selesai).
 */
function jp_reset_by_laporan(PDO $pdo, int $laporanId): void
{
    if ($laporanId <= 0) {
        return;
    }
    try {
        $pdo->prepare("UPDATE Proses_Pemeriksaan SET status = 'belum', laporan_id = NULL WHERE laporan_id = ?")
            ->execute([$laporanId]);
    } catch (Throwable $e) {
        // tabel belum dibuat / error lain: jangan gagalkan penghapusan laporan
    }
}

/**
 * Jaring pengaman: pemeriksaan yang laporan_id-nya menunjuk laporan yang sudah
 * tidak ada (mis. dihapus lewat jalur lain) dikembalikan ke "belum".
 */
function jp_self_heal(PDO $pdo): void
{
    try {
        $pdo->exec("UPDATE Proses_Pemeriksaan jp
                    LEFT JOIN Laporan_Pemeriksaan lp ON lp.id = jp.laporan_id
                    SET jp.status = 'belum', jp.laporan_id = NULL
                    WHERE jp.laporan_id IS NOT NULL AND lp.id IS NULL");
    } catch (Throwable $e) {
    }
}