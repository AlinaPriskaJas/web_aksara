<?php
// includes/dokumen_helper.php
//
// Helper terpusat supaya SEMUA dokumen yang dibuat/diunggah di modul mana pun
// (Surat, Sertifikat Ahli, Suket K3, dst) otomatis ikut tersimpan sebagai
// arsip di tabel Dokumen_Digital -- yang berarti otomatis muncul juga di
// halaman "Digital Sign" (admin/it/direksi -> digital.php).
//
// Dipanggil dari titik-titik penyimpanan dokumen di:
//   admin/surat.php, ahlik3/surat.php, it/surat.php, direksi/surat.php
//   admin/sertifikat_ahli.php, ahlik3/sertifikat_ahli.php
//
// Lihat arp_peta_modul_dokumen() di bawah untuk mapping modul_sumber ->
// info tampilan (tab & ikon) dan link balik ke halaman asal dokumen,
// dipakai oleh halaman Digital Sign.

if (!function_exists('arp_arsipkan_dokumen')) {
    /**
     * Simpan/perbarui satu baris arsip di Dokumen_Digital.
     *
     * @param PDO   $conn  Koneksi PDO/koneksi aktif (mysqli tidak didukung)
     * @param array $data  [
     *   'nama_dokumen'  => string wajib
     *   'kategori'      => string wajib, salah satu ENUM Dokumen_Digital.kategori
     *                      ('Suket K3','Sertifikat Ahli','Legal Perusahaan',
     *                       'Kontrak Klien','Laporan','Lainnya')
     *   'file_path'     => string wajib, link Drive atau path lokal berkas
     *   'modul_sumber'  => string wajib, mis. 'Surat Keluar', 'Sertifikat Ahli'
     *                      (dipakai untuk tab & dedupe, lihat arp_peta_modul_dokumen)
     *   'ref_id'        => int|null  id baris asal di tabel modulnya -- dipakai
     *                      untuk dedupe (1 dokumen sumber = 1 baris arsip) dan
     *                      untuk membangun link balik ke halaman asal
     *   'klien_id'      => int|null
     *   'visibilitas'   => 'Internal'|'Client'|'Publik' (default 'Internal')
     *   'diupload_oleh' => int wajib, id Users
     *   'drive_file_id' => string|null
     *   'drive_link'    => string|null
     * ]
     * @return int|null id baris Dokumen_Digital yang dibuat/diperbarui, null bila gagal/dilewati
     */
    function arp_arsipkan_dokumen(PDO $conn, array $data): ?int
    {
        $nama         = trim((string) ($data['nama_dokumen'] ?? ''));
        $kategori     = $data['kategori'] ?? 'Lainnya';
        $filePath     = trim((string) ($data['file_path'] ?? ''));
        $modulSumber  = $data['modul_sumber'] ?? 'Lainnya';
        $refId        = isset($data['ref_id']) && $data['ref_id'] !== '' ? (int) $data['ref_id'] : null;
        $klienId      = isset($data['klien_id']) && $data['klien_id'] !== '' ? (int) $data['klien_id'] : null;
        $visibilitas  = $data['visibilitas'] ?? 'Internal';
        $diuploadOleh = (int) ($data['diupload_oleh'] ?? 0);
        $driveFileId  = $data['drive_file_id'] ?? null;
        $driveLink    = $data['drive_link'] ?? null;

        // Data tidak lengkap -> jangan diarsipkan, tapi jangan sampai menggagalkan
        // proses utama (pembuatan surat/sertifikat dsb) yang memanggil helper ini.
        if ($nama === '' || $filePath === '' || $diuploadOleh <= 0) {
            return null;
        }

        try {
            // Dedupe berdasarkan (modul_sumber, ref_id): satu dokumen sumber cukup
            // punya SATU baris arsip. Kalau baris lama masih ada (mis. surat yang
            // sama diproses ulang / sertifikat diganti filenya / dikirim ke client
            // sehingga visibilitasnya berubah), baris lama di-UPDATE, bukan menumpuk
            // baris baru.
            if ($refId !== null) {
                $cek = $conn->prepare("SELECT id FROM Dokumen_Digital WHERE modul_sumber = :modul AND ref_id = :ref LIMIT 1");
                $cek->execute(['modul' => $modulSumber, 'ref' => $refId]);
                $existingId = $cek->fetchColumn();

                if ($existingId) {
                    $upd = $conn->prepare("
                        UPDATE Dokumen_Digital SET
                            nama_dokumen = :nama,
                            kategori     = :kategori,
                            file_path    = :file_path,
                            drive_file_id = :drive_file_id,
                            drive_link    = :drive_link,
                            klien_id     = COALESCE(:klien_id, klien_id),
                            visibilitas  = :visibilitas
                        WHERE id = :id
                    ");
                    $upd->execute([
                        'nama'          => $nama,
                        'kategori'      => $kategori,
                        'file_path'     => $filePath,
                        'drive_file_id' => $driveFileId,
                        'drive_link'    => $driveLink,
                        'klien_id'      => $klienId,
                        'visibilitas'   => $visibilitas,
                        'id'            => $existingId,
                    ]);
                    return (int) $existingId;
                }
            }

            $ins = $conn->prepare("
                INSERT INTO Dokumen_Digital
                    (nama_dokumen, kategori, file_path, drive_file_id, drive_link, modul_sumber, ref_id, klien_id, visibilitas, diupload_oleh)
                VALUES
                    (:nama, :kategori, :file_path, :drive_file_id, :drive_link, :modul, :ref, :klien_id, :visibilitas, :user_id)
            ");
            $ins->execute([
                'nama'          => $nama,
                'kategori'      => $kategori,
                'file_path'     => $filePath,
                'drive_file_id' => $driveFileId,
                'drive_link'    => $driveLink,
                'modul'         => $modulSumber,
                'ref'           => $refId,
                'klien_id'      => $klienId,
                'visibilitas'   => $visibilitas,
                'user_id'       => $diuploadOleh,
            ]);
            return (int) $conn->lastInsertId();
        } catch (PDOException $e) {
            // Kegagalan arsip TIDAK BOLEH menggagalkan transaksi utama pemanggil.
            error_log('[arp_arsipkan_dokumen] Gagal mengarsipkan dokumen: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('arp_peta_modul_dokumen')) {
    /**
     * Mapping modul_sumber (kolom Dokumen_Digital.modul_sumber) -> info tampilan
     * (label tab, ikon) dan pola URL balik ke halaman asal dokumen tsb.
     *
     * Dipakai oleh halaman Digital Sign (admin/it/direksi -> digital.php) agar
     * tab yang ditampilkan SELALU mengikuti jenis dokumen yang benar-benar ada
     * di arsip, dan setiap baris dokumen bisa "Buka Halaman Asal".
     *
     * Placeholder pada 'url': {role} diganti folder role yang sedang login
     * (admin/it/direksi/ahlik3), {ref_id} diganti Dokumen_Digital.ref_id.
     * 'url' => null berarti dokumen ini tidak punya halaman modul asal
     * (mis. diunggah manual lewat repository IT).
     */
    function arp_peta_modul_dokumen(): array
    {
        return [
            'Surat Keluar'    => ['label' => 'Surat Keluar',       'icon' => 'bi-send-fill',             'url' => '{role}/surat.php?tab=surat_keluar&highlight={ref_id}'],
            'Surat Masuk'     => ['label' => 'Surat Masuk',        'icon' => 'bi-envelope-fill',         'url' => '{role}/surat.php?tab=surat_masuk&highlight={ref_id}'],
            'Surat'           => ['label' => 'Surat Terkirim',     'icon' => 'bi-envelope-check-fill',   'url' => '{role}/surat.php?tab=surat_keluar&highlight={ref_id}'],
            'Sertifikat Ahli' => ['label' => 'Sertifikat Ahli',    'icon' => 'bi-patch-check-fill',      'url' => '{role}/sertifikat_ahli.php?highlight={ref_id}'],
            'Suket K3'        => ['label' => 'Suket K3',           'icon' => 'bi-file-earmark-medical',  'url' => '{role}/suket.php?highlight={ref_id}'],
            'Laporan'         => ['label' => 'Laporan Pemeriksaan','icon' => 'bi-clipboard-data-fill',   'url' => '{role}/suket.php?highlight={ref_id}'],
            'IT Repository'   => ['label' => 'Repository IT',      'icon' => 'bi-hdd-network-fill',      'url' => null],
        ];
    }
}

if (!function_exists('arp_info_modul_dokumen')) {
    /** Ambil info tampilan untuk satu modul_sumber, dengan fallback aman kalau belum dipetakan. */
    function arp_info_modul_dokumen(?string $modulSumber): array
    {
        $peta = arp_peta_modul_dokumen();
        return $peta[$modulSumber] ?? ['label' => $modulSumber ?: 'Lainnya', 'icon' => 'bi-file-earmark-fill', 'url' => null];
    }
}

if (!function_exists('arp_link_sumber_dokumen')) {
    /** Bangun URL balik ke halaman asal dokumen untuk role & ref_id tertentu, atau null kalau tidak ada. */
    function arp_link_sumber_dokumen(?string $modulSumber, ?int $refId, string $role): ?string
    {
        $info = arp_info_modul_dokumen($modulSumber);
        if (empty($info['url']) || $refId === null) {
            return null;
        }
        return str_replace(['{role}', '{ref_id}'], [$role, (string) $refId], $info['url']);
    }
}
