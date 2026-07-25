<?php
/**
 * includes/klien_helper.php
 *
 * Helper untuk modul Data_Klien (perusahaan client).
 *
 * Tujuan: data perusahaan SELALU diisi sendiri oleh client di halaman
 * profile-nya (client/profile.php), bukan diketik ulang oleh admin.
 * Admin cukup memverifikasi/mengaktifkan akun, sehingga risiko salah
 * ketik nama perusahaan/alamat/PIC oleh admin bisa dihilangkan.
 *
 * Dipakai di: registrasi.php dan client/profile.php.
 */

if (!function_exists('arp_generate_kode_klien')) {
    /**
     * Buat kode_klien unik otomatis dengan format KLN-0001, KLN-0002, dst.
     */
    function arp_generate_kode_klien(PDO $conn): string
    {
        $stmt   = $conn->query("SELECT COUNT(*) FROM Data_Klien");
        $urutan = (int) $stmt->fetchColumn() + 1;
        $kode   = 'KLN-' . str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);

        $cek = $conn->prepare("SELECT COUNT(*) FROM Data_Klien WHERE kode_klien = :kode");
        $cek->execute([':kode' => $kode]);
        while ((int) $cek->fetchColumn() > 0) {
            $urutan++;
            $kode = 'KLN-' . str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
            $cek->execute([':kode' => $kode]);
        }

        return $kode;
    }
}

if (!function_exists('arp_buat_data_klien_kosong')) {
    /**
     * Buat baris Data_Klien kosong (belum ada nama perusahaan) dan langsung
     * ditautkan ke akun client yang baru dibuat. Statusnya 'Non-aktif' sampai
     * client melengkapi data perusahaan sendiri di halaman profil dan admin
     * memverifikasi/mengaktifkannya.
     *
     * @return int|null id Data_Klien yang baru dibuat, atau null jika gagal.
     */
    function arp_buat_data_klien_kosong(PDO $conn, int $user_id): ?int
    {
        try {
            $kode_klien = arp_generate_kode_klien($conn);
            $stmt = $conn->prepare("
                INSERT INTO Data_Klien (kode_klien, nama_perusahaan, alamat, status, pic_nama, pic_whatsapp, pic_email, user_id)
                VALUES (:kode_klien, '', NULL, 'Non-aktif', NULL, NULL, NULL, :user_id)
            ");
            $stmt->execute([
                ':kode_klien' => $kode_klien,
                ':user_id'    => $user_id,
            ]);
            return (int) $conn->lastInsertId();
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('arp_klien_lengkap')) {
    /**
     * Cek apakah data perusahaan sudah cukup lengkap diisi oleh client.
     *
     * @param array|false|null $data_klien Baris Data_Klien
     */
    function arp_klien_lengkap($data_klien): bool
    {
        if (!$data_klien) {
            return false;
        }

        $wajib = ['nama_perusahaan', 'alamat', 'pic_nama', 'pic_whatsapp', 'pic_email'];
        foreach ($wajib as $kolom) {
            if (trim((string) ($data_klien[$kolom] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}