<?php
// includes/cuti_surat_helper.php
//
// Helper untuk membangun & mengelola "Surat Cuti & Pengalihan Tugas" untuk
// Cuti Tahunan, memakai template generik dari Template_Master (nama:
// "Form Cuti Dan Pengalihan Tugas", terhubung ke Kode_Surat lewat
// Kode_Template) -- BUKAN lagi file lokal di storage/templates/.
//
// Surat yang dihasilkan disimpan sebagai baris biasa di tabel Surat (arah
// 'Keluar'), SAMA seperti surat-surat lain yang dibuat lewat admin/surat.php.
// Tidak ada kolom baru di tabel Cuti -- keterkaitan Cuti <-> Surat disimpan
// di dalam Surat.isi_data (JSON) sebagai isi_data.cuti_id, lalu dicari balik
// lewat JSON_EXTRACT saat dibutuhkan (lihat arp_ambil_surat_untuk_cuti()).
//
// Dipakai oleh:
// - admin/cuti.php               -> saat pengajuan Cuti Tahunan dikirim, buat
//                                    DRAFT surat (arp_generate_dan_unggah_surat_cuti_draft)
//                                    supaya direksi bisa lihat filenya di Drive.
// - direksi/approval.php         -> saat Cuti Tahunan Disetujui, timpa surat
//                                    yang sama jadi versi final (nama penyetuju
//                                    terisi); saat Ditolak, hapus surat & filenya.
// - includes/generate_cuti_surat.php -> tombol "Cetak Surat Cuti" (unduh
//                                    manual) + self-heal kalau belum ada.

require_once __DIR__ . '/functions.php';

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Ambil kode_surat + template_master yang terhubung untuk "Form Cuti Dan
 * Pengalihan Tugas". Dicari lewat NAMA (bukan id) supaya tetap benar walau
 * id-nya berbeda di environment lain.
 */
function arp_ambil_kode_template_cuti(PDO $conn): ?array
{
    $stmt = $conn->prepare("
        SELECT k.id AS kode_id, k.kode, k.nama AS nama_kode,
               t.id AS template_id, t.drive_file_id, t.drive_link, t.format
        FROM Kode_Surat k
        JOIN Kode_Template kt ON kt.kode_id = k.id
        JOIN Template_Master t ON t.id = kt.template_id
        WHERE k.nama = 'Permohonan Cuti Dan Pengalihan Tugas'
        ORDER BY kt.is_default DESC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Cari baris Surat yang sudah pernah dibuat untuk satu pengajuan Cuti
 * tertentu (ditandai lewat isi_data.cuti_id), kalau ada.
 */
function arp_ambil_surat_untuk_cuti(PDO $conn, int $cuti_id): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT * FROM Surat
            WHERE JSON_UNQUOTE(JSON_EXTRACT(isi_data, '$.cuti_id')) = :cuti_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['cuti_id' => $cuti_id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Ambil link Drive Surat Cuti untuk satu pengajuan, kalau sudah ada.
 * Dipakai di admin/cuti.php & direksi/approval.php untuk kolom "Surat Cuti".
 */
function arp_ambil_link_surat_cuti(PDO $conn, int $cuti_id): ?string
{
    $s = arp_ambil_surat_untuk_cuti($conn, $cuti_id);
    return ($s && !empty($s['drive_link'])) ? $s['drive_link'] : null;
}

/**
 * Susun data (Cuti + Cuti_Serah_Terima) menjadi bentuk yang siap dipakai
 * generateSuratDocx() (lihat includes/functions.php): $dataForm untuk field
 * biasa, $items untuk tabel "Uraian Tugas yang Diserahkan".
 */
function arp_bangun_data_surat_cuti(PDO $conn, int $cuti_id): array
{
    $stmt = $conn->prepare(
        "SELECT c.*, u.nama_lengkap AS nama_pemohon_user,
                approver.nama_lengkap AS nama_penyetuju_user
         FROM Cuti c
         JOIN Users u ON u.id = c.user_id
         LEFT JOIN Approval a ON a.id = c.approval_id
         LEFT JOIN Users approver ON approver.id = a.approver_id
         WHERE c.id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $cuti_id]);
    $cuti = $stmt->fetch();

    if (!$cuti) {
        throw new RuntimeException('Pengajuan cuti tidak ditemukan.');
    }
    if ($cuti['jenis_cuti'] !== 'Cuti Tahunan') {
        throw new RuntimeException('Surat Cuti & Pengalihan Tugas hanya tersedia untuk Cuti Tahunan.');
    }

    try {
        $stmtItem = $conn->prepare("SELECT * FROM Cuti_Serah_Terima WHERE cuti_id = :cuti_id ORDER BY urutan ASC");
        $stmtItem->execute(['cuti_id' => $cuti_id]);
        $itemsMentah = $stmtItem->fetchAll();
    } catch (PDOException $e) {
        $itemsMentah = [];
    }

    $items = [];
    foreach ($itemsMentah as $it) {
        $items[] = ['deskripsi' => $it['deskripsi'], 'status' => $it['status'] ?: '-'];
    }

    $namaKaryawan = $cuti['nama_pemohon_user'] ?? '-';
    $namaPenyetuju = $cuti['nama_penyetuju_user'] ?: '-';
    $tahun = date('Y', strtotime($cuti['tgl_mulai']));

    $dataForm = [
        'tanggal_surat' => formatTanggalIndonesia(date('Y-m-d')),
        'nama_penerima' => $cuti['nama_penerima'] ?: '-',
        'nama_karyawan' => $namaKaryawan,
        'nama_pemohon' => $namaKaryawan,
        'jabatan_karyawan' => $cuti['jabatan_karyawan'] ?: '-',
        'divisi_karyawan' => $cuti['divisi_karyawan'] ?: '-',
        'sub_divisi_karyawan' => $cuti['sub_divisi_karyawan'] ?: '-',  
        'alasan_cuti' => $cuti['alasan'] ?: '-',
        'jumlah_hari' => (string) $cuti['total_durasi'],
        'tanggal_mulai' => formatTanggalIndonesia($cuti['tgl_mulai']),
        'tanggal_selesai' => formatTanggalIndonesia($cuti['tgl_selesai']),
        'tahun' => $tahun,
        'tanggal_serah_terima' => $cuti['tanggal_serah_terima'] ? formatTanggalIndonesia($cuti['tanggal_serah_terima']) : '-',
        'nama_penerima_tugas' => $cuti['nama_penerima_tugas'] ?: '-',
        'jabatan_penerima_tugas' => $cuti['jabatan_penerima_tugas'] ?: '-',
        'sub_divisi_penerima_tugas' => $cuti['sub_divisi_penerima_tugas'] ?: '-',
        'divisi_penerima_tugas' => $cuti['divisi_penerima_tugas'] ?: '-',
        'nama_mengetahui' => $cuti['nama_mengetahui'] ?: '-',
        'nama_penandatangan' => $namaPenyetuju,
    ];

    return [
        'cuti' => $cuti,
        'dataForm' => $dataForm,
        'items' => $items,
        'namaKaryawan' => $namaKaryawan,
    ];
}

/**
 * Bangun file .docx Surat Cuti & Pengalihan Tugas (LOKAL, belum diunggah)
 * untuk keperluan UNDUH LANGSUNG (tombol "Cetak Surat Cuti") atau PRATINJAU.
 *
 * @return array{docx_path:string, nama_file_dasar:string, nama_karyawan:string}
 * @throws RuntimeException kalau data tidak valid atau template tidak terhubung.
 */
function arp_generate_surat_cuti_docx(PDO $conn, int $cuti_id, bool $wajib_disetujui = true): array
{
    $bahan = arp_bangun_data_surat_cuti($conn, $cuti_id);
    $cuti = $bahan['cuti'];

    // $wajib_disetujui = false dipakai untuk PRATINJAU (direksi boleh lihat
    // isi surat sebelum approve/reject, walau status masih Menunggu).
    if ($wajib_disetujui && $cuti['status'] !== 'Disetujui') {
        throw new RuntimeException('Surat hanya bisa dibuat untuk pengajuan yang sudah Disetujui.');
    }

    $kodeTemplate = arp_ambil_kode_template_cuti($conn);
    if (!$kodeTemplate || empty($kodeTemplate['drive_file_id'])) {
        throw new RuntimeException('Template "Form Cuti Dan Pengalihan Tugas" belum terhubung ke Google Drive. Upload dulu lewat menu Persuratan > Upload Template.');
    }

    // Pakai nomor surat yang SAMA kalau sebelumnya sudah pernah dibuat untuk
    // pengajuan ini (supaya nomor tidak berubah-ubah tiap kali dicetak ulang).
    $suratLama = arp_ambil_surat_untuk_cuti($conn, $cuti_id);
    $nomorSurat = $suratLama['nomor'] ?? resolveNomorSurat($conn, (int) $kodeTemplate['kode_id']);

    $fileHasilRelatif = arp_dengan_template_sementara(
        $kodeTemplate['drive_file_id'],
        function ($pathTemplateLokal) use ($bahan, $nomorSurat, $kodeTemplate) {
            return generateSuratDocx(
                $pathTemplateLokal,
                $bahan['dataForm'],
                $bahan['items'],
                $nomorSurat,
                [],
                $kodeTemplate['nama_kode'] ?? 'Form Cuti Dan Pengalihan Tugas'
            );
        }
    );

    $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
    $docxPath = rtrim($basePath, '/\\') . '/' . $fileHasilRelatif;

    return [
        'docx_path' => $docxPath,
        'nama_file_dasar' => pathinfo($fileHasilRelatif, PATHINFO_FILENAME),
        'nama_karyawan' => $bahan['namaKaryawan'],
    ];
}

/**
 * Bangun surat, lalu SIMPAN sebagai baris di tabel Surat (arah Keluar) &
 * unggah/perbarui filenya di Google Drive.
 *
 * - Kalau pengajuan ini BELUM pernah punya Surat -> upload baru + INSERT.
 * - Kalau SUDAH pernah (mis. draft yang dibuat waktu pengajuan) -> file yang
 *   SAMA di Drive ditimpa isinya (arp_timpa_konten_drive, mekanisme yang sama
 *   dipakai untuk edit template), lalu baris Surat di-UPDATE. Ini supaya
 *   link yang sudah dilihat/dibagikan sebelumnya (draft) tetap valid begitu
 *   surat jadi versi final saat disetujui -- tidak ada file yatim menumpuk
 *   di Drive.
 *
 * PENTING: fungsi ini SENGAJA tidak pernah melempar exception -- dipanggil
 * dari direksi/approval.php maupun halaman pengajuan cuti SESUDAH transaksi
 * database di-commit. Kegagalan apa pun di sini dicatat lewat
 * arp_drive_last_error() saja.
 *
 * @return string|null Link Drive kalau berhasil, null kalau gagal.
 */
function arp_generate_dan_unggah_surat_cuti(PDO $conn, int $cuti_id, bool $wajib_disetujui = true): ?string
{
    try {
        $bahan = arp_bangun_data_surat_cuti($conn, $cuti_id);
        $cuti = $bahan['cuti'];

        if ($wajib_disetujui && $cuti['status'] !== 'Disetujui') {
            arp_drive_set_last_error('Pengajuan cuti belum berstatus Disetujui.');
            return null;
        }

        $kodeTemplate = arp_ambil_kode_template_cuti($conn);
        if (!$kodeTemplate || empty($kodeTemplate['drive_file_id'])) {
            arp_drive_set_last_error('Template "Form Cuti Dan Pengalihan Tugas" belum terhubung ke Google Drive. Upload dulu lewat menu Persuratan > Upload Template.');
            return null;
        }

        $suratLama = arp_ambil_surat_untuk_cuti($conn, $cuti_id);
        $nomorSurat = $suratLama['nomor'] ?? resolveNomorSurat($conn, (int) $kodeTemplate['kode_id']);

        $fileHasilRelatif = arp_dengan_template_sementara(
            $kodeTemplate['drive_file_id'],
            function ($pathTemplateLokal) use ($bahan, $nomorSurat, $kodeTemplate) {
                return generateSuratDocx(
                    $pathTemplateLokal,
                    $bahan['dataForm'],
                    $bahan['items'],
                    $nomorSurat,
                    [],
                    $kodeTemplate['nama_kode'] ?? 'Form Cuti Dan Pengalihan Tugas'
                );
            }
        );

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
        $pathLokalPenuh = rtrim($basePath, '/\\') . '/' . $fileHasilRelatif;

        $namaKaryawan = $bahan['namaKaryawan'];
        $perihal = 'Permohonan Cuti & Pengalihan Tugas - ' . $namaKaryawan;
        $statusSurat = $wajib_disetujui ? 'Disetujui' : 'Draft';
        $isiData = json_encode(
            array_merge($bahan['dataForm'], ['cuti_id' => $cuti_id, 'sumber' => 'cuti']),
            JSON_UNESCAPED_UNICODE
        );

        // ---------- Sudah ada Surat sebelumnya -> timpa file yang sama ----------
        if ($suratLama && !empty($suratLama['drive_file_id'])) {
            $berhasil = arp_timpa_konten_drive(
                $suratLama['drive_file_id'],
                $pathLokalPenuh,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            );
            @unlink($pathLokalPenuh);

            if (!$berhasil) {
                return null;
            }

            try {
                $upd = $conn->prepare("UPDATE Surat SET status = :status, tujuan = :tujuan, perihal = :perihal, isi_data = :isi_data WHERE id = :id");
                $upd->execute([
                    'status' => $statusSurat,
                    'tujuan' => $bahan['dataForm']['nama_penerima'],
                    'perihal' => $perihal,
                    'isi_data' => $isiData,
                    'id' => $suratLama['id'],
                ]);
            } catch (Throwable $e) {
                arp_drive_set_last_error('Surat berhasil ditimpa di Drive tapi gagal disimpan ke database: ' . $e->getMessage());
                return null;
            }

            return $suratLama['drive_link'];
        }

        // ---------- Belum ada Surat -> upload baru + INSERT baris Surat ----------
        $namaFileDrive = arp_nama_file_tanggal_pengirim($namaKaryawan, 'docx');
        $hasilDrive = arp_upload_ke_drive(
            $pathLokalPenuh,
            $namaFileDrive,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $cuti_id,
            'Surat Cuti'
        );
        @unlink($pathLokalPenuh);

        if (!$hasilDrive || empty($hasilDrive['link'])) {
            return null;
        }

        try {
            $nomorAgenda = generateNomorAgenda($conn, 'Keluar');
            $insert = $conn->prepare("INSERT INTO Surat
                (nomor_agenda, nomor, kode_id, template_id, perihal, status, arah, tujuan, dibuat_oleh, tgl_dibuat, file_hasil, drive_file_id, drive_link, isi_data)
                VALUES (:nomor_agenda, :nomor, :kode_id, :template_id, :perihal, :status, 'Keluar', :tujuan, :dibuat_oleh, CURDATE(), :file_hasil, :drive_file_id, :drive_link, :isi_data)");
            $insert->execute([
                'nomor_agenda' => $nomorAgenda,
                'nomor' => $nomorSurat,
                'kode_id' => $kodeTemplate['kode_id'],
                'template_id' => $kodeTemplate['template_id'],
                'perihal' => $perihal,
                'status' => $statusSurat,
                'tujuan' => $bahan['dataForm']['nama_penerima'],
                'dibuat_oleh' => $cuti['user_id'],
                'file_hasil' => $hasilDrive['link'],
                'drive_file_id' => $hasilDrive['file_id'] ?? null,
                'drive_link' => $hasilDrive['link'],
                'isi_data' => $isiData,
            ]);
        } catch (Throwable $e) {
            arp_drive_set_last_error('Surat berhasil diunggah ke Drive tapi gagal disimpan ke database: ' . $e->getMessage());
            return null;
        }

        return $hasilDrive['link'];
    } catch (Throwable $e) {
        arp_drive_set_last_error($e->getMessage());
        return null;
    }
}

/**
 * Bangun & unggah DRAFT Surat Cuti begitu pemohon MENGAJUKAN Cuti Tahunan
 * (status masih "Menunggu") -- supaya direksi bisa langsung membuka filenya
 * di Drive saat meninjau. Best-effort, tidak pernah melempar exception.
 */
function arp_generate_dan_unggah_surat_cuti_draft(PDO $conn, int $cuti_id): ?string
{
    try {
        return arp_generate_dan_unggah_surat_cuti($conn, $cuti_id, false);
    } catch (Throwable $e) {
        error_log('Gagal membuat draft Surat Cuti untuk Cuti #' . $cuti_id . ': ' . $e->getMessage());
        arp_drive_set_last_error($e->getMessage());
        return null;
    }
}

/**
 * Hapus Surat Cuti (draft/final) dari Drive & database saat direksi MENOLAK
 * pengajuan Cuti Tahunan -- suratnya sudah tidak relevan lagi.
 * Best-effort, tidak pernah melempar exception.
 */
function arp_hapus_surat_cuti_drive(PDO $conn, int $cuti_id): void
{
    try {
        $surat = arp_ambil_surat_untuk_cuti($conn, $cuti_id);
        if (!$surat) {
            return;
        }
        if (!empty($surat['drive_file_id'])) {
            $berhasil = arp_hapus_file_drive($surat['drive_file_id']);
            if (!$berhasil) {
                error_log('Gagal menghapus Surat Cuti (file_id=' . $surat['drive_file_id'] . ') dari Drive untuk Cuti #' . $cuti_id . '.');
            }
        }
        $conn->prepare("DELETE FROM Surat WHERE id = :id")->execute(['id' => $surat['id']]);
    } catch (Throwable $e) {
        error_log('Gagal membersihkan Surat Cuti untuk Cuti #' . $cuti_id . ' (ditolak): ' . $e->getMessage());
    }
}