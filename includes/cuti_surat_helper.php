<?php
// includes/cuti_surat_helper.php
//
// Helper BERSAMA untuk membangun dokumen "Surat Cuti & Pengalihan Tugas" (.docx)
// dari data satu pengajuan Cuti Tahunan (tabel Cuti + Cuti_Serah_Terima + nama
// penyetuju dari Approval). Dipakai oleh dua pemanggil:
// - includes/generate_cuti_surat.php  -> tombol "Cetak Surat Cuti" (unduh manual,
//   dipanggil user), juga otomatis mengunggah hasilnya ke Drive kalau pengajuan
//   itu belum pernah punya surat_cuti_link (self-heal untuk data lama).
// - direksi/approval.php              -> begitu Cuti Tahunan disetujui, surat
//   otomatis dibuat & WAJIB diunggah ke Drive (lihat arp_upload_ke_drive di
//   drive_helper.php), linknya disimpan ke Cuti.surat_cuti_link.
//
// Template: storage/templates/permohonan-cuti-dan-pengalihan-tugas.docx
// Macro tanda tangan "Pemohon" = ${nama_karyawan}, "Menyetujui/ Menolak" =
// ${nama_menyetujui} (diambil dari Users.nama_lengkap milik Approval.approver_id).

require_once __DIR__ . '/functions.php';

use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Bangun file .docx Surat Cuti & Pengalihan Tugas untuk satu pengajuan Cuti Tahunan
 * yang sudah Disetujui.
 *
 * @return array{docx_path:string, nama_file_dasar:string, nama_karyawan:string}
 * @throws RuntimeException kalau data tidak valid, template tidak ada, atau gagal disimpan.
 */
function arp_generate_surat_cuti_docx(PDO $conn, int $cuti_id, bool $wajib_disetujui = true): array
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
    // $wajib_disetujui = false dipakai untuk PRATINJAU: direksi perlu bisa
    // melihat isi Surat Cuti & Pengalihan Tugas SEBELUM memutuskan
    // approve/reject, jadi pratinjau ini boleh dibuat kapan pun (status
    // Menunggu sekalipun). Suratnya baru "resmi" (diunggah ke Drive & linknya
    // disimpan ke Cuti.surat_cuti_link) setelah benar-benar Disetujui -- lihat
    // arp_generate_dan_unggah_surat_cuti() di bawah, yang selalu memanggil
    // fungsi ini dengan $wajib_disetujui default (true).
    if ($wajib_disetujui && $cuti['status'] !== 'Disetujui') {
        throw new RuntimeException('Surat hanya bisa dibuat untuk pengajuan yang sudah Disetujui.');
    }

    // ================== AMBIL DAFTAR URAIAN TUGAS ==================
    try {
        $stmtItem = $conn->prepare("SELECT * FROM Cuti_Serah_Terima WHERE cuti_id = :cuti_id ORDER BY urutan ASC");
        $stmtItem->execute(['cuti_id' => $cuti_id]);
        $items = $stmtItem->fetchAll();
    } catch (PDOException $e) {
        $items = [];
    }

    // ================== SIAPKAN TEMPLATE ==================
    $templatePath = realpath(__DIR__ . '/../storage/templates/permohonan-cuti-dan-pengalihan-tugas.docx');
    if (!$templatePath || !file_exists($templatePath)) {
        throw new RuntimeException('Template Surat Cuti tidak ditemukan di storage/templates/.');
    }

    $namaKaryawan = $cuti['nama_pemohon_user'] ?? '-';
    $namaPenyetuju = $cuti['nama_penyetuju_user'] ?: '-';
    $tahun = date('Y', strtotime($cuti['tgl_mulai']));

    $dataMacro = [
        'tanggal_surat' => formatTanggalIndonesia(date('Y-m-d')),
        'nama_penerima' => $cuti['nama_penerima'] ?: '-',
        'nama_karyawan' => $namaKaryawan,
        'jabatan_karyawan' => $cuti['jabatan_karyawan'] ?: '-',
        'divisi_karyawan' => $cuti['divisi_karyawan'] ?: '-',
        'alasan_cuti' => $cuti['alasan'] ?: '-',
        'jumlah_hari' => (string) $cuti['total_durasi'],
        'tanggal_mulai' => formatTanggalIndonesia($cuti['tgl_mulai']),
        'tanggal_selesai' => formatTanggalIndonesia($cuti['tgl_selesai']),
        'tahun' => $tahun,
        // Tanda tangan "Pemohon" & "Menyetujui/ Menolak" -- template versi baru
        // sudah punya macro terpisah untuk masing-masing kolom.
        'nama_menyetujui' => $namaPenyetuju,
        'tanggal_serah_terima' => $cuti['tanggal_serah_terima'] ? formatTanggalIndonesia($cuti['tanggal_serah_terima']) : '-',
        // Macro versi "Serah Terima Tugas" di template ada spasi tambahan di dalam kurung kurawal:
        'jabatan_karyawan ' => $cuti['jabatan_karyawan'] ?: '-',
        'sub_divisi_ karyawan ' => $cuti['sub_divisi_karyawan'] ?: '-',
        'divisi_ karyawan ' => $cuti['divisi_karyawan'] ?: '-',
        'nama_penerima_tugas' => $cuti['nama_penerima_tugas'] ?: '-',
        'jabatan_penerima_tugas' => $cuti['jabatan_penerima_tugas'] ?: '-',
        'sub_divisi_penerima_tugas' => $cuti['sub_divisi_penerima_tugas'] ?: '-',
        'divisi_penerima_tugas' => $cuti['divisi_penerima_tugas'] ?: '-',
        'nama_mengetahui' => $cuti['nama_mengetahui'] ?: '-',
    ];

    try {
        $processor = new TemplateProcessor($templatePath);

        foreach ($dataMacro as $macro => $nilai) {
            $processor->setValue($macro, htmlspecialchars((string) $nilai, ENT_QUOTES));
        }

        // Tabel "Uraian tugas yang diserahkan" -- clone baris minimal 1x supaya
        // macro item_no/item_deskripsi/item_status tidak tersisa mentah di dokumen.
        $jumlahBaris = max(count($items), 1);
        $processor->cloneRow('item_no', $jumlahBaris);

        if (count($items) > 0) {
            foreach ($items as $idx => $item) {
                $baris = $idx + 1;
                $processor->setValue('item_no#' . $baris, (string) $baris);
                $processor->setValue('item_deskripsi#' . $baris, htmlspecialchars((string) $item['deskripsi'], ENT_QUOTES));
                $processor->setValue('item_status#' . $baris, htmlspecialchars((string) ($item['status'] ?: '-'), ENT_QUOTES));
            }
        } else {
            $processor->setValue('item_no#1', '-');
            $processor->setValue('item_deskripsi#1', '-');
            $processor->setValue('item_status#1', '-');
        }

        $namaFileDasar = 'Surat_Cuti_' . preg_replace('/[^A-Za-z0-9]+/', '_', $namaKaryawan) . '_' . $cuti_id;
        $outputDir = sys_get_temp_dir();
        $docxPath = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $namaFileDasar . '_' . uniqid() . '.docx';
        $processor->saveAs($docxPath);
    } catch (Exception $e) {
        throw new RuntimeException('Gagal membuat Surat Cuti: ' . $e->getMessage());
    }

    return [
        'docx_path' => $docxPath,
        'nama_file_dasar' => $namaFileDasar,
        'nama_karyawan' => $namaKaryawan,
    ];
}

/**
 * Ambil drive_file_id yang tersimpan untuk suatu pengajuan Cuti, kalau ada.
 * Dipakai supaya file lama (draft/versi sebelumnya) bisa dihapus dari Drive
 * begitu ada file baru yang menggantikannya, atau saat pengajuan ditolak.
 */
function arp_ambil_drive_file_id_cuti(PDO $conn, int $cuti_id): ?string
{
    try {
        $s = $conn->prepare("SELECT drive_file_id FROM Cuti WHERE id = :id");
        $s->execute(['id' => $cuti_id]);
        $v = $s->fetchColumn();
        return $v !== false && $v !== null && $v !== '' ? (string) $v : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Bangun surat (docx) lalu unggah ke Drive folder "Surat Cuti", simpan link
 * & file_id-nya ke Cuti.surat_cuti_link / Cuti.drive_file_id.
 *
 * File lokal sementara SELALU dihapus di akhir (baik sukses maupun gagal), jadi
 * fungsi ini tidak cocok dipakai kalau file docx-nya masih mau dikirim ke
 * browser sesudahnya -- untuk itu pakai arp_generate_surat_cuti_docx() langsung.
 *
 * @param bool $wajib_disetujui true = surat resmi (hanya untuk pengajuan yang
 *             sudah Disetujui, dipakai saat approval). false = DRAFT (boleh
 *             dibuat saat status masih Menunggu, dipakai begitu pemohon
 *             mengajukan cuti supaya direksi bisa langsung melihat filenya di
 *             Drive sebelum memutuskan).
 * @param bool $hapus_file_lama_setelah_sukses kalau true dan sebelumnya sudah
 *             ada drive_file_id tersimpan (mis. draft lama), file lama itu
 *             dihapus dari Drive SETELAH file baru berhasil diunggah -- supaya
 *             tidak ada file yatim menumpuk di Drive tiap kali surat dibuat
 *             ulang (draft -> final saat approval).
 *
 * PENTING: fungsi ini SENGAJA tidak pernah melempar exception (semua kegagalan
 * ditangkap & jadi return null) -- dipanggil dari direksi/approval.php maupun
 * halaman pengajuan cuti SESUDAH transaksi database di-commit, jadi kalau ada
 * exception yang lolos ke situ, blok catch di sana akan mencoba rollBack()
 * transaksi yang sudah ter-commit dan malah menimbulkan error baru yang lebih
 * parah. Kegagalan apa pun di sini (misalnya kolom surat_cuti_link belum ada
 * karena migrations/2026_08_31_add_surat_cuti_link.sql belum dijalankan, atau
 * Drive lagi bermasalah) cukup dicatat lewat arp_drive_last_error().
 *
 * @return string|null Link Drive kalau berhasil, null kalau gagal (detail ada di arp_drive_last_error()).
 */
function arp_generate_dan_unggah_surat_cuti(PDO $conn, int $cuti_id, bool $wajib_disetujui = true, bool $hapus_file_lama_setelah_sukses = true): ?string
{
    try {
        $hasil = arp_generate_surat_cuti_docx($conn, $cuti_id, $wajib_disetujui);
    } catch (RuntimeException $e) {
        arp_drive_set_last_error($e->getMessage());
        return null;
    }

    // Catat file_id yang lama (kalau ada) SEBELUM upload baru -- dipakai untuk
    // dihapus belakangan setelah file baru sukses tersimpan, supaya kalau upload
    // baru gagal, file lama yang masih sah tetap ada (tidak kehilangan surat).
    $fileIdLama = $hapus_file_lama_setelah_sukses ? arp_ambil_drive_file_id_cuti($conn, $cuti_id) : null;

    $namaPengirim = $hasil['nama_karyawan'];
    $namaFileDrive = arp_nama_file_tanggal_pengirim($namaPengirim, 'docx');

    try {
        $hasilDrive = arp_upload_ke_drive(
            $hasil['docx_path'],
            $namaFileDrive,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $cuti_id,
            'Surat Cuti'
        );
    } catch (Throwable $e) {
        @unlink($hasil['docx_path']);
        arp_drive_set_last_error($e->getMessage());
        return null;
    }

    @unlink($hasil['docx_path']);

    if (!$hasilDrive || empty($hasilDrive['link'])) {
        return null;
    }

    try {
        $upd = $conn->prepare("UPDATE Cuti SET surat_cuti_link = :link, drive_file_id = :file_id WHERE id = :id");
        $upd->execute([
            'link' => $hasilDrive['link'],
            'file_id' => $hasilDrive['file_id'] ?? null,
            'id' => $cuti_id,
        ]);
    } catch (Throwable $e) {
        // Upload ke Drive-nya sendiri sudah sukses (filenya sudah ada di sana),
        // tapi link-nya gagal disimpan ke database -- kemungkinan besar kolom
        // surat_cuti_link belum ada karena migrations/2026_08_31_add_surat_cuti_link.sql
        // belum dijalankan. Anggap gagal supaya admin diberi tahu & tahu harus
        // menjalankan migration-nya, walau berkasnya sendiri tidak hilang.
        arp_drive_set_last_error('Surat berhasil diunggah ke Drive tapi gagal disimpan ke database (kemungkinan migration belum dijalankan): ' . $e->getMessage());
        return null;
    }

    // Bersihkan file lama (mis. draft yang dibuat waktu pengajuan) SETELAH file
    // baru sukses tersimpan -- best effort, jangan sampai kegagalan hapus file
    // lama menggagalkan proses yang sebenarnya sudah berhasil.
    if ($fileIdLama && $fileIdLama !== ($hasilDrive['file_id'] ?? null)) {
        try {
            arp_hapus_file_drive($fileIdLama);
        } catch (Throwable $e) {
            error_log('Gagal menghapus Surat Cuti lama (file_id=' . $fileIdLama . ') dari Drive untuk Cuti #' . $cuti_id . ': ' . $e->getMessage());
        }
    }

    return $hasilDrive['link'];
}

/**
 * Bangun & unggah DRAFT Surat Cuti & Pengalihan Tugas begitu pemohon MENGAJUKAN
 * Cuti Tahunan (status masih "Menunggu") -- supaya direksi bisa langsung
 * membuka filenya di Google Drive saat meninjau pengajuan, bukan cuma
 * pratinjau on-the-fly. Kalau nanti disetujui, arp_generate_dan_unggah_surat_cuti()
 * akan membuat ulang surat versi final (dengan nama penyetuju) dan menggantikan
 * draft ini di Drive.
 *
 * Best-effort & tidak pernah melempar exception -- dipanggil setelah transaksi
 * pengajuan cuti di-commit, jadi kegagalan di sini TIDAK BOLEH menggagalkan
 * pengajuan cuti yang sudah tersimpan. Kalau gagal, kolom surat_cuti_link tetap
 * kosong dan direksi akan melihat link pratinjau on-the-fly seperti biasa.
 *
 * @return string|null Link Drive kalau berhasil, null kalau gagal/dilewati.
 */
function arp_generate_dan_unggah_surat_cuti_draft(PDO $conn, int $cuti_id): ?string
{
    try {
        return arp_generate_dan_unggah_surat_cuti($conn, $cuti_id, false, true);
    } catch (Throwable $e) {
        error_log('Gagal membuat draft Surat Cuti untuk Cuti #' . $cuti_id . ': ' . $e->getMessage());
        arp_drive_set_last_error($e->getMessage());
        return null;
    }
}

/**
 * Hapus Surat Cuti & Pengalihan Tugas dari Google Drive untuk satu pengajuan
 * (draft maupun versi final), lalu kosongkan Cuti.surat_cuti_link &
 * Cuti.drive_file_id. Dipanggil saat direksi MENOLAK pengajuan Cuti Tahunan --
 * suratnya sudah tidak relevan lagi (permohonan ditolak), jadi tidak perlu
 * (dan tidak boleh) tetap tersimpan di Drive.
 *
 * Best-effort: dipanggil SESUDAH transaksi approval di-commit (sama seperti
 * arp_generate_dan_unggah_surat_cuti()), jadi fungsi ini tidak pernah melempar
 * exception -- kegagalan hanya dicatat lewat error_log().
 */
function arp_hapus_surat_cuti_drive(PDO $conn, int $cuti_id): void
{
    try {
        $fileId = arp_ambil_drive_file_id_cuti($conn, $cuti_id);

        if ($fileId) {
            $berhasil = arp_hapus_file_drive($fileId);
            if (!$berhasil) {
                error_log('Gagal menghapus Surat Cuti (file_id=' . $fileId . ') dari Drive untuk Cuti #' . $cuti_id . ' yang ditolak.');
            }
        }

        $upd = $conn->prepare("UPDATE Cuti SET surat_cuti_link = NULL, drive_file_id = NULL WHERE id = :id");
        $upd->execute(['id' => $cuti_id]);
    } catch (Throwable $e) {
        error_log('Gagal membersihkan Surat Cuti dari Drive untuk Cuti #' . $cuti_id . ' (ditolak): ' . $e->getMessage());
    }
}