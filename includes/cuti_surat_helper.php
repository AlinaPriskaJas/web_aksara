<?php
// includes/cuti_surat_helper.php
//
// PERUBAHAN: Surat Cuti & Pengalihan Tugas TIDAK LAGI dicatat sebagai baris
// di tabel Surat (surat.php). Hasil generate (docx) diunggah ke Google Drive
// dan link-nya disimpan LANGSUNG di kolom Cuti.drive_file_id & Cuti.drive_link
// (BUKAN ke kolom Cuti.lampiran). Nomor surat disimpan di
// Cuti.isi_data['__nomor_surat_cuti'] supaya nomornya tetap konsisten walau
// suratnya digenerate ulang (draft -> final, atau saat pengajuan diedit).

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
               t.id AS template_id, t.drive_file_id, t.drive_link, t.format, t.fields_json
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
 * Ambil daftar field ${...} (fields, table_fields, blocks) dari template
 * Cuti secara LIVE dari Google Drive, dipakai untuk membangun form "Ajukan
 * Cuti Tahunan" secara dinamis mengikuti isi template Word.
 */
function arp_ambil_fields_template_cuti(PDO $conn): array
{
    $kodeTemplate = arp_ambil_kode_template_cuti($conn);
    $kosong = ['fields' => [], 'table_fields' => [], 'blocks' => [], 'invoice_fields' => []];
    if (!$kodeTemplate) {
        return $kosong;
    }
    return muatFieldsTemplateLive($conn, $kodeTemplate);
}

/**
 * BARU: Link Surat Cuti sekarang diambil LANGSUNG dari Cuti.drive_link
 * (bukan lagi dicari lewat tabel Surat).
 */
function arp_ambil_link_surat_cuti(PDO $conn, int $cuti_id): ?string
{
    try {
        $stmt = $conn->prepare("SELECT drive_link FROM Cuti WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $cuti_id]);
        $link = $stmt->fetchColumn();
        return ($link !== false && $link !== null && $link !== '') ? $link : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Susun data untuk generateSuratDocx() SEPENUHNYA dari Cuti.isi_data (JSON
 * hasil form dinamis). Tetap menyediakan beberapa field wajib standar
 * (tanggal_mulai, tanggal_selesai, jumlah_hari, alasan_cuti, tanggal_surat)
 * yang selalu diisi otomatis dari kolom baku tabel Cuti.
 *
 * $items diambil dari isi_data['__items'] (tabel item_..., kalau template
 * punya tabel semacam "Uraian Tugas yang Diserahkan").
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

    $isiData = [];
    if (!empty($cuti['isi_data'])) {
        $isiData = json_decode($cuti['isi_data'], true) ?: [];
    }

    $items = [];
    if (!empty($isiData['__items']) && is_array($isiData['__items'])) {
        foreach ($isiData['__items'] as $baris) {
            if (is_array($baris)) {
                $items[] = $baris;
            }
        }
    }

    $namaKaryawan = $cuti['nama_pemohon_user'] ?? '-';
    $namaPenyetuju = $cuti['nama_penyetuju_user'] ?: '-';

    // Kunci internal yang TIDAK boleh ikut jadi field ${...} di dokumen.
    // '__nomor_surat_cuti' ditambahkan di sini (dipakai internal saja untuk
    // menjaga konsistensi nomor surat, bukan untuk placeholder Word).
    $kunciInternal = ['__items', '__ringkasan', '__blok', '__nomor_surat_cuti', 'cuti_id', 'sumber'];

    $dataForm = [];
    foreach ($isiData as $k => $v) {
        if (in_array($k, $kunciInternal, true)) {
            continue;
        }
        if (is_string($v) && preg_match('/tanggal|tgl/i', (string) $k) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $dataForm[$k] = formatTanggalIndonesia($v);
        } else {
            $dataForm[$k] = $v;
        }
    }

    // Field wajib baku -- selalu diambil dari kolom asli tabel Cuti supaya
    // akurat & tidak bisa "digeser" isinya lewat form dinamis.
    $dataForm['nama_pemohon'] = $namaKaryawan;
    $dataForm['alasan_cuti'] = $cuti['alasan'] ?: '-';
    $dataForm['jumlah_hari'] = (string) $cuti['total_durasi'];
    $dataForm['tanggal_mulai'] = formatTanggalIndonesia($cuti['tgl_mulai']);
    $dataForm['tanggal_selesai'] = formatTanggalIndonesia($cuti['tgl_selesai']);
    $dataForm['tahun'] = date('Y', strtotime($cuti['tgl_mulai']));
    $dataForm['nama_penandatangan'] = $namaPenyetuju;

    if (empty($dataForm['tanggal_surat'])) {
        $dataForm['tanggal_surat'] = formatTanggalIndonesia(date('Y-m-d'));
    }
    if (empty($dataForm['nama_karyawan'])) {
        $dataForm['nama_karyawan'] = $namaKaryawan;
    }

    return [
        'cuti' => $cuti,
        'isiDataMentah' => $isiData, // dipakai untuk resolve/simpan nomor surat
        'dataForm' => $dataForm,
        'items' => $items,
        'namaKaryawan' => $namaKaryawan,
    ];
}

/**
 * Ambil nomor surat yang sudah pernah dibuat untuk pengajuan ini (tersimpan
 * di isi_data['__nomor_surat_cuti']), atau generate nomor baru kalau belum
 * pernah ada. Ini menggantikan mekanisme lama yang mengambil nomor dari
 * baris Surat yang sudah ada.
 */
function arp_resolve_nomor_surat_cuti(PDO $conn, array $isiDataMentah, int $kodeId): string
{
    if (!empty($isiDataMentah['__nomor_surat_cuti'])) {
        return (string) $isiDataMentah['__nomor_surat_cuti'];
    }
    return resolveNomorSurat($conn, $kodeId);
}

/**
 * Bangun file .docx Surat Cuti & Pengalihan Tugas (LOKAL, belum diunggah).
 * Dipakai untuk "Cetak Surat Cuti" / pratinjau on-the-fly.
 */
function arp_generate_surat_cuti_docx(PDO $conn, int $cuti_id, bool $wajib_disetujui = true): array
{
    $bahan = arp_bangun_data_surat_cuti($conn, $cuti_id);
    $cuti = $bahan['cuti'];

    if ($wajib_disetujui && $cuti['status'] !== 'Disetujui') {
        throw new RuntimeException('Surat hanya bisa dibuat untuk pengajuan yang sudah Disetujui.');
    }

    $kodeTemplate = arp_ambil_kode_template_cuti($conn);
    if (!$kodeTemplate || empty($kodeTemplate['drive_file_id'])) {
        throw new RuntimeException('Template "Form Cuti Dan Pengalihan Tugas" belum terhubung ke Google Drive. Upload dulu lewat menu Persuratan > Upload Template.');
    }

    $nomorSurat = arp_resolve_nomor_surat_cuti($conn, $bahan['isiDataMentah'], (int) $kodeTemplate['kode_id']);

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
 * Bangun surat, lalu unggah/perbarui filenya di Google Drive, dan simpan
 * link-nya LANGSUNG ke Cuti.drive_file_id & Cuti.drive_link.
 *
 * TIDAK lagi menyentuh kolom Cuti.lampiran, dan TIDAK lagi
 * membuat/mengubah baris apa pun di tabel Surat.
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

        $nomorSurat = arp_resolve_nomor_surat_cuti($conn, $bahan['isiDataMentah'], (int) $kodeTemplate['kode_id']);

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

        // Simpan nomor surat ke isi_data supaya tetap sama di generate berikutnya
        // (mis. saat status berubah dari draft -> final, atau saat diedit).
        $isiDataBaru = $bahan['isiDataMentah'];
        $isiDataBaru['__nomor_surat_cuti'] = $nomorSurat;
        $isiDataJson = json_encode($isiDataBaru, JSON_UNESCAPED_UNICODE);

        // ---------- Sudah pernah diunggah -> timpa file yang sama di Drive ----------
        if (!empty($cuti['drive_file_id'])) {
            $berhasil = arp_timpa_konten_drive(
                $cuti['drive_file_id'],
                $pathLokalPenuh,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            );
            @unlink($pathLokalPenuh);

            if (!$berhasil) {
                return null;
            }

            try {
                $upd = $conn->prepare("UPDATE Cuti SET isi_data = :isi_data WHERE id = :id");
                $upd->execute([
                    'isi_data' => $isiDataJson,
                    'id' => $cuti_id,
                ]);
            } catch (Throwable $e) {
                arp_drive_set_last_error('Surat berhasil ditimpa di Drive tapi gagal disimpan ke database: ' . $e->getMessage());
                return null;
            }

            return $cuti['drive_link'];
        }

        // ---------- Belum pernah diunggah -> upload baru ----------
        $namaFileDrive = arp_nama_file_tanggal_pengirim($namaKaryawan, 'docx');
        $hasilDrive = arp_upload_ke_drive(
            $pathLokalPenuh,
            $namaFileDrive,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $cuti_id,
            'Surat_Cuti'
        );
        @unlink($pathLokalPenuh);

        if (!$hasilDrive || empty($hasilDrive['link'])) {
            return null;
        }

        try {
            $upd = $conn->prepare("UPDATE Cuti SET drive_file_id = :drive_file_id, drive_link = :drive_link, isi_data = :isi_data WHERE id = :id");
            $upd->execute([
                'drive_file_id' => $hasilDrive['file_id'] ?? null,
                'drive_link' => $hasilDrive['link'],
                'isi_data' => $isiDataJson,
                'id' => $cuti_id,
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
 * Hapus file Surat Cuti dari Google Drive & bersihkan Cuti.drive_file_id /
 * Cuti.drive_link (dipanggil mis. saat pengajuan Cuti Tahunan ditolak).
 */
function arp_hapus_surat_cuti_drive(PDO $conn, int $cuti_id): void
{
    try {
        $stmt = $conn->prepare("SELECT drive_file_id FROM Cuti WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $cuti_id]);
        $driveFileId = $stmt->fetchColumn();

        if (empty($driveFileId)) {
            return;
        }

        $berhasil = arp_hapus_file_drive($driveFileId);
        if (!$berhasil) {
            error_log('Gagal menghapus Surat Cuti (file_id=' . $driveFileId . ') dari Drive untuk Cuti #' . $cuti_id . '.');
        }

        $conn->prepare("UPDATE Cuti SET drive_file_id = NULL, drive_link = NULL WHERE id = :id")
            ->execute(['id' => $cuti_id]);
    } catch (Throwable $e) {
        error_log('Gagal membersihkan Surat Cuti untuk Cuti #' . $cuti_id . ' (ditolak): ' . $e->getMessage());
    }
}