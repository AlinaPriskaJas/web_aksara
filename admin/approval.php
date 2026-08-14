<?php
// admin/approval.php
session_start();
require_once "../config/koneksi.php";

// TODO: ganti dengan user_id dari sesi login sebenarnya (proses_login.php belum tersambung penuh).
$admin_id = $_SESSION['user_id'] ?? 1;

$page_title = "Approval Pengajuan Pemeriksaan";
$flash = null;

// ================== TAB AKTIF (Pemeriksaan / Surat) ==================
$tab_aktif = $_GET['tab'] ?? 'pemeriksaan';
if (!in_array($tab_aktif, ['pemeriksaan', 'surat'], true)) {
    $tab_aktif = 'pemeriksaan';
}

// ================== PROSES SETUJUI / TOLAK PENGAJUAN ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_approval'])) {
    $pengajuan_id = (int) ($_POST['pengajuan_id'] ?? 0);
    $decision     = $_POST['decision'] ?? ''; // 'approve' | 'reject'
    $catatan      = trim($_POST['catatan'] ?? '');

    $status_map = [
        'approve' => ['pengajuan' => 'Diverifikasi', 'approval' => 'Disetujui'],
        'reject'  => ['pengajuan' => 'Ditolak',      'approval' => 'Ditolak'],
    ];

    if ($pengajuan_id <= 0 || !isset($status_map[$decision])) {
        $flash = ['type' => 'danger', 'message' => 'Permintaan tidak valid.'];
    } elseif ($decision === 'reject' && $catatan === '') {
        $flash = ['type' => 'danger', 'message' => 'Catatan wajib diisi saat menolak pengajuan.'];
    } else {
        try {
            $conn->beginTransaction();

            $cek = $conn->prepare("SELECT diajukan_oleh, status FROM Pengajuan_Pemeriksaan WHERE id = :id FOR UPDATE");
            $cek->execute([':id' => $pengajuan_id]);
            $row = $cek->fetch();

            if (!$row) {
                throw new RuntimeException("Data pengajuan tidak ditemukan.");
            }
            if ($row['status'] !== 'Menunggu Verifikasi') {
                throw new RuntimeException("Pengajuan ini sudah diproses sebelumnya (" . $row['status'] . ").");
            }

            $status_pengajuan = $status_map[$decision]['pengajuan'];
            $status_approval  = $status_map[$decision]['approval'];

            $update = $conn->prepare("
                UPDATE Pengajuan_Pemeriksaan
                SET status = :status, catatan_admin = :catatan, diproses_oleh = :admin_id
                WHERE id = :id
            ");
            $update->execute([
                ':status'   => $status_pengajuan,
                ':catatan'  => $catatan !== '' ? $catatan : null,
                ':admin_id' => $admin_id,
                ':id'       => $pengajuan_id,
            ]);

            $cekApproval = $conn->prepare("
                SELECT id FROM Approval
                WHERE jenis_pengajuan = 'Pengajuan Pemeriksaan' AND ref_id = :ref_id AND status = 'Menunggu'
                ORDER BY id DESC LIMIT 1
            ");
            $cekApproval->execute([':ref_id' => $pengajuan_id]);
            $approvalRow = $cekApproval->fetch();

            if ($approvalRow) {
                $updApproval = $conn->prepare("
                    UPDATE Approval
                    SET status = :status, approver_id = :approver_id, catatan = :catatan, tgl_aksi = NOW()
                    WHERE id = :id
                ");
                $updApproval->execute([
                    ':status'      => $status_approval,
                    ':approver_id' => $admin_id,
                    ':catatan'     => $catatan !== '' ? $catatan : null,
                    ':id'          => $approvalRow['id'],
                ]);
            } else {
                $insertApproval = $conn->prepare("
                    INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, approver_id, level, status, catatan, tgl_aksi)
                    VALUES ('Pengajuan Pemeriksaan', :ref_id, :requester_id, :approver_id, 1, :status, :catatan, NOW())
                ");
                $insertApproval->execute([
                    ':ref_id'       => $pengajuan_id,
                    ':requester_id' => $row['diajukan_oleh'],
                    ':approver_id'  => $admin_id,
                    ':status'       => $status_approval,
                    ':catatan'      => $catatan !== '' ? $catatan : null,
                ]);
            }

            // Beri tahu client (yang mengajukan) hasil verifikasinya lewat Notifikasi
            try {
                $stmtNotifClient = $conn->prepare("
                    INSERT INTO Notifikasi (user_id, judul, pesan, modul_terkait, ref_id, sudah_dibaca)
                    VALUES (:user_id, :judul, :pesan, 'Pengajuan_Pemeriksaan', :ref_id, 0)
                ");
                $stmtNotifClient->execute([
                    ':user_id' => $row['diajukan_oleh'],
                    ':judul'   => $decision === 'approve' ? 'Pengajuan Disetujui' : 'Pengajuan Ditolak',
                    ':pesan'   => $decision === 'approve'
                        ? 'Pengajuan pemeriksaan Anda telah diverifikasi dan disetujui Admin.'
                        : 'Pengajuan pemeriksaan Anda ditolak Admin. Catatan: ' . ($catatan !== '' ? $catatan : '-'),
                    ':ref_id'  => $pengajuan_id,
                ]);
            } catch (PDOException $e) {
                // Jangan gagalkan proses approval hanya karena notifikasi gagal dibuat
            }

            $conn->commit();

            catatAudit(
                $conn,
                'Approval',
                $decision === 'approve' ? 'Verifikasi' : 'Tolak',
                "Memverifikasi pengajuan pemeriksaan #{$pengajuan_id} -> {$status_pengajuan}",
                ['status' => $row['status']],
                ['status' => $status_pengajuan, 'catatan' => $catatan],
                $admin_id
            );


            $flash = [
                'type' => 'success',
                'message' => $decision === 'approve'
                    ? 'Pengajuan pemeriksaan berhasil disetujui.'
                    : 'Pengajuan pemeriksaan berhasil ditolak.',
            ];
        } catch (Exception $e) {
            $conn->rollBack();
            $flash = ['type' => 'danger', 'message' => 'Gagal memproses: ' . $e->getMessage()];
        }
    }

    $_SESSION['approval_flash'] = $flash;
    $redirect_status = $_GET['status'] ?? '';
    header("Location: approval.php" . ($redirect_status !== '' ? '?status=' . urlencode($redirect_status) : ''));
    exit;
}

// ================== PROSES EDIT PENGAJUAN (semua kolom, bukan cuma tanggal) ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_pengajuan'])) {
    $pengajuan_id      = (int) ($_POST['pengajuan_id'] ?? 0);
    $nama_perusahaan   = trim($_POST['nama_perusahaan'] ?? '');
    $diajukan_oleh     = (int) ($_POST['diajukan_oleh'] ?? 0);
    $tanggal_baru      = trim($_POST['tanggal_diinginkan'] ?? '');

    $unit_id_raw    = $_POST['unit_id'] ?? [];
    $unit_nama_raw  = $_POST['unit_nama'] ?? [];
    $unit_jenis_raw = $_POST['unit_jenis'] ?? [];
    $unit_hapus_raw = $_POST['unit_hapus'] ?? '';

    $unit_id_raw    = is_array($unit_id_raw) ? $unit_id_raw : [];
    $unit_nama_raw  = is_array($unit_nama_raw) ? $unit_nama_raw : [];
    $unit_jenis_raw = is_array($unit_jenis_raw) ? $unit_jenis_raw : [];

    // Susun baris unit yang masih valid (nama tidak kosong)
    $unit_rows = [];
    foreach ($unit_nama_raw as $idx => $nama) {
        $nama = trim((string) $nama);
        if ($nama === '') continue;
        $unit_rows[] = [
            'id'    => isset($unit_id_raw[$idx]) ? (int) $unit_id_raw[$idx] : 0,
            'nama'  => $nama,
            'jenis' => trim((string) ($unit_jenis_raw[$idx] ?? '')),
        ];
    }

    // ID unit lama yang dihapus user lewat tombol "Hapus" di modal
    $unit_hapus_ids = array_filter(array_map('intval', explode(',', $unit_hapus_raw)));

    if ($pengajuan_id <= 0 || $nama_perusahaan === '' || $diajukan_oleh <= 0 || $tanggal_baru === '') {
        $flash = ['type' => 'danger', 'message' => 'Nama Perusahaan, Diajukan Oleh, dan Tanggal Diinginkan wajib diisi.'];
    } elseif (empty($unit_rows)) {
        $flash = ['type' => 'danger', 'message' => 'Minimal harus ada 1 unit/objek yang diperiksa.'];
    } else {
        try {
            $conn->beginTransaction();

            $cekPengajuan = $conn->prepare("SELECT id FROM Pengajuan_Pemeriksaan WHERE id = :id FOR UPDATE");
            $cekPengajuan->execute([':id' => $pengajuan_id]);
            if (!$cekPengajuan->fetch()) {
                throw new RuntimeException("Data pengajuan tidak ditemukan.");
            }

            $cekUser = $conn->prepare("SELECT id FROM Users WHERE id = :id");
            $cekUser->execute([':id' => $diajukan_oleh]);
            if (!$cekUser->fetch()) {
                throw new RuntimeException("Pengaju yang dipilih tidak valid.");
            }

            // Ringkasan/legacy: kolom jenis_pemeriksaan & jenis_objek di Pengajuan_Pemeriksaan
            // mengikuti unit pertama (urutan teratas) agar halaman lain yang masih baca kolom ini tetap konsisten.
            $unit_utama = $unit_rows[0];

            $updPengajuan = $conn->prepare("
                UPDATE Pengajuan_Pemeriksaan
                SET nama_perusahaan   = :nama_perusahaan,
                    diajukan_oleh     = :diajukan_oleh,
                    tanggal_diinginkan = :tanggal,
                    jenis_pemeriksaan = :jenis_pemeriksaan,
                    jenis_objek       = :jenis_objek
                WHERE id = :id
            ");
            $updPengajuan->execute([
                ':nama_perusahaan'   => $nama_perusahaan,
                ':diajukan_oleh'     => $diajukan_oleh,
                ':tanggal'           => $tanggal_baru,
                ':jenis_pemeriksaan' => $unit_utama['jenis'] !== '' ? $unit_utama['jenis'] : null,
                ':jenis_objek'       => $unit_utama['nama'],
                ':id'                => $pengajuan_id,
            ]);

            // Hapus unit yang dibuang user (kalau ada)
            if (!empty($unit_hapus_ids)) {
                $placeholders = implode(',', array_fill(0, count($unit_hapus_ids), '?'));
                $delUnit = $conn->prepare("
                    DELETE FROM Pengajuan_Pemeriksaan_Unit
                    WHERE pengajuan_id = ? AND id IN ($placeholders)
                ");
                $delUnit->execute(array_merge([$pengajuan_id], $unit_hapus_ids));
            }

            $updUnit = $conn->prepare("
                UPDATE Pengajuan_Pemeriksaan_Unit
                SET nama_unit = :nama_unit, jenis_pemeriksaan = :jenis_pemeriksaan, urutan = :urutan
                WHERE id = :id AND pengajuan_id = :pengajuan_id
            ");
            $insUnit = $conn->prepare("
                INSERT INTO Pengajuan_Pemeriksaan_Unit (pengajuan_id, id_jenis, nama_unit, jenis_pemeriksaan, urutan)
                VALUES (:pengajuan_id, NULL, :nama_unit, :jenis_pemeriksaan, :urutan)
            ");

            foreach ($unit_rows as $urutan => $unit) {
                if ($unit['id'] > 0) {
                    $updUnit->execute([
                        ':nama_unit'         => $unit['nama'],
                        ':jenis_pemeriksaan' => $unit['jenis'] !== '' ? $unit['jenis'] : null,
                        ':urutan'            => $urutan,
                        ':id'                => $unit['id'],
                        ':pengajuan_id'      => $pengajuan_id,
                    ]);
                } else {
                    $insUnit->execute([
                        ':pengajuan_id'      => $pengajuan_id,
                        ':nama_unit'         => $unit['nama'],
                        ':jenis_pemeriksaan' => $unit['jenis'] !== '' ? $unit['jenis'] : null,
                        ':urutan'            => $urutan,
                    ]);
                }
            }

            $conn->commit();
            catatAudit(
                $conn,
                'Approval',
                'Ubah',
                "Mengubah data pengajuan pemeriksaan #{$pengajuan_id}",
                null,
                ['nama_perusahaan' => $nama_perusahaan, 'diajukan_oleh' => $diajukan_oleh, 'tanggal_diinginkan' => $tanggal_baru],
                $admin_id
            );
            $flash = ['type' => 'success', 'message' => 'Pengajuan berhasil diperbarui.'];
        } catch (Exception $e) {
            $conn->rollBack();
            $flash = ['type' => 'danger', 'message' => 'Gagal memperbarui pengajuan: ' . $e->getMessage()];
        }
    }

    $_SESSION['approval_flash'] = $flash;
    $redirect_status = $_GET['status'] ?? '';
    header("Location: approval.php" . ($redirect_status !== '' ? '?status=' . urlencode($redirect_status) : ''));
    exit;
}

// ================== PROSES GANTI SATU FILE PADA PENGAJUAN ==================
// Admin bisa mengganti SATU file/dokumen pendukung yang dikirim client, tanpa
// menyentuh file lain ataupun data pengajuan (unit, jenis, tanggal, dsb).
// Hanya baris Dokumen_Digital dengan id = :dokumen_id yang diperbarui.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ganti_file_pengajuan'])) {
    require_once "../includes/drive_helper.php";

    $dokumen_id   = (int) ($_POST['dokumen_id'] ?? 0);
    $pengajuan_id = (int) ($_POST['pengajuan_id'] ?? 0);

    if ($dokumen_id <= 0) {
        $flash = ['type' => 'danger', 'message' => 'File yang ingin diganti tidak valid.'];
    } elseif (empty($_FILES['file_baru']) || $_FILES['file_baru']['error'] === UPLOAD_ERR_NO_FILE) {
        $flash = ['type' => 'danger', 'message' => 'Pilih file baru terlebih dahulu.'];
    } elseif ($_FILES['file_baru']['error'] !== UPLOAD_ERR_OK) {
        $flash = ['type' => 'danger', 'message' => 'Gagal mengunggah file (kode error: ' . $_FILES['file_baru']['error'] . ').'];
    } else {
        try {
            // Pastikan dokumen ini memang milik pengajuan pemeriksaan (bukan modul lain),
            // supaya endpoint ini tidak bisa dipakai mengganti file di modul lain.
            $cekDok = $conn->prepare("
                SELECT id, ref_id, klien_id, drive_file_id
                FROM Dokumen_Digital
                WHERE id = :id AND modul_sumber = 'Pengajuan_Pemeriksaan'
            ");
            $cekDok->execute([':id' => $dokumen_id]);
            $dokLama = $cekDok->fetch();

            if (!$dokLama) {
                throw new RuntimeException("Dokumen tidak ditemukan.");
            }
            if ($pengajuan_id > 0 && (int) $dokLama['ref_id'] !== $pengajuan_id) {
                throw new RuntimeException("Dokumen tidak sesuai dengan pengajuan ini.");
            }

            $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
            $max_size    = 10 * 1024 * 1024;

            $nama_asli = $_FILES['file_baru']['name'];
            $ext = strtolower(pathinfo($nama_asli, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) {
                throw new RuntimeException("Tipe file tidak didukung. Gunakan PDF, JPG, atau PNG.");
            }
            if ($_FILES['file_baru']['size'] > $max_size) {
                throw new RuntimeException("Ukuran file melebihi 10MB.");
            }

            $tmp_path = $_FILES['file_baru']['tmp_name'];
            $mime     = $_FILES['file_baru']['type'] ?: 'application/octet-stream';

            $hasil_drive = arp_upload_ke_drive($tmp_path, $nama_asli, $mime, (int) $dokLama['ref_id'], 'Pengajuan_Pemeriksaan');

            if (!$hasil_drive || empty($hasil_drive['link'])) {
                throw new RuntimeException("Gagal mengunggah file baru ke Drive: " . arp_drive_last_error());
            }

            // Hanya baris file ini yang diperbarui -- file lain pada pengajuan yang sama tidak disentuh.
            $updDok = $conn->prepare("
                UPDATE Dokumen_Digital
                SET nama_dokumen = :nama_dokumen,
                    file_path    = :file_path,
                    drive_file_id = :drive_file_id,
                    drive_link    = :drive_link
                WHERE id = :id
            ");
            $updDok->execute([
                ':nama_dokumen'  => $nama_asli,
                ':file_path'     => $hasil_drive['link'],
                ':drive_file_id' => $hasil_drive['file_id'],
                ':drive_link'    => $hasil_drive['link'],
                ':id'            => $dokumen_id,
            ]);

            // File lama di Drive dihapus belakangan (best-effort), tidak menggagalkan proses kalau gagal.
            if (!empty($dokLama['drive_file_id'])) {
                try {
                    arp_hapus_file_drive($dokLama['drive_file_id']);
                } catch (Throwable $e) {
                    // abaikan; file lama menjadi sampah di Drive tapi data di DB sudah benar
                }
            }

            catatAudit(
                $conn,
                'Approval',
                'Ganti File',
                "Mengganti file pendukung pengajuan pemeriksaan #{$dokLama['ref_id']} (dokumen #{$dokumen_id})",
                null,
                ['nama_dokumen' => $nama_asli],
                $admin_id
            );

            $flash = ['type' => 'success', 'message' => 'File berhasil diganti.'];
        } catch (Exception $e) {
            $flash = ['type' => 'danger', 'message' => 'Gagal mengganti file: ' . $e->getMessage()];
        }
    }

    $_SESSION['approval_flash'] = $flash;
    $redirect_status = $_GET['status'] ?? '';
    header("Location: approval.php" . ($redirect_status !== '' ? '?status=' . urlencode($redirect_status) : ''));
    exit;
}

// ================== PROSES SETUJUI / TOLAK SURAT ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_approval_surat'])) {
    $surat_id = (int) ($_POST['surat_id'] ?? 0);
    $decision = $_POST['decision'] ?? ''; // 'approve' | 'reject'
    $catatan  = trim($_POST['catatan'] ?? '');

    $status_map_surat = [
        'approve' => 'Disetujui',
        'reject'  => 'Ditolak',
    ];

    if ($surat_id <= 0 || !isset($status_map_surat[$decision])) {
        $flash = ['type' => 'danger', 'message' => 'Permintaan tidak valid.'];
    } elseif ($decision === 'reject' && $catatan === '') {
        $flash = ['type' => 'danger', 'message' => 'Catatan wajib diisi saat menolak surat.'];
    } else {
        try {
            $conn->beginTransaction();

            $cekSurat = $conn->prepare("SELECT dibuat_oleh, status, nomor, perihal FROM surat WHERE id = :id FOR UPDATE");
            $cekSurat->execute([':id' => $surat_id]);
            $rowSurat = $cekSurat->fetch();

            if (!$rowSurat) {
                throw new RuntimeException("Data surat tidak ditemukan.");
            }
            if ($rowSurat['status'] !== 'Diajukan') {
                throw new RuntimeException("Surat ini sudah diproses sebelumnya (" . $rowSurat['status'] . ").");
            }

            $status_surat_baru = $status_map_surat[$decision];

            $updSurat = $conn->prepare("UPDATE surat SET status = :status WHERE id = :id");
            $updSurat->execute([
                ':status' => $status_surat_baru,
                ':id'     => $surat_id,
            ]);

            $cekApprovalSurat = $conn->prepare("
                SELECT id FROM Approval
                WHERE jenis_pengajuan = 'Surat' AND ref_id = :ref_id AND status = 'Menunggu'
                ORDER BY id DESC LIMIT 1
            ");
            $cekApprovalSurat->execute([':ref_id' => $surat_id]);
            $approvalRowSurat = $cekApprovalSurat->fetch();

            if ($approvalRowSurat) {
                $updApprovalSurat = $conn->prepare("
                    UPDATE Approval
                    SET status = :status, approver_id = :approver_id, catatan = :catatan, tgl_aksi = NOW()
                    WHERE id = :id
                ");
                $updApprovalSurat->execute([
                    ':status'      => $status_surat_baru,
                    ':approver_id' => $admin_id,
                    ':catatan'     => $catatan !== '' ? $catatan : null,
                    ':id'          => $approvalRowSurat['id'],
                ]);
            } else {
                $insertApprovalSurat = $conn->prepare("
                    INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, approver_id, level, status, catatan, tgl_aksi)
                    VALUES ('Surat', :ref_id, :requester_id, :approver_id, 1, :status, :catatan, NOW())
                ");
                $insertApprovalSurat->execute([
                    ':ref_id'       => $surat_id,
                    ':requester_id' => $rowSurat['dibuat_oleh'],
                    ':approver_id'  => $admin_id,
                    ':status'       => $status_surat_baru,
                    ':catatan'      => $catatan !== '' ? $catatan : null,
                ]);
            }

            try {
                $stmtNotifSurat = $conn->prepare("
                    INSERT INTO Notifikasi (user_id, judul, pesan, modul_terkait, ref_id, sudah_dibaca)
                    VALUES (:user_id, :judul, :pesan, 'Surat', :ref_id, 0)
                ");
                $stmtNotifSurat->execute([
                    ':user_id' => $rowSurat['dibuat_oleh'],
                    ':judul'   => $decision === 'approve' ? 'Surat Disetujui' : 'Surat Ditolak',
                    ':pesan'   => $decision === 'approve'
                        ? 'Surat "' . $rowSurat['perihal'] . '" telah disetujui Admin.'
                        : 'Surat "' . $rowSurat['perihal'] . '" ditolak Admin. Catatan: ' . ($catatan !== '' ? $catatan : '-'),
                    ':ref_id'  => $surat_id,
                ]);
            } catch (PDOException $e) {
                // Jangan gagalkan proses approval hanya karena notifikasi gagal dibuat
            }

            $conn->commit();

            catatAudit(
                $conn,
                'Approval',
                $decision === 'approve' ? 'Setujui Surat' : 'Tolak Surat',
                "Memverifikasi surat #{$surat_id} (" . ($rowSurat['nomor'] ?? '') . ") -> {$status_surat_baru}",
                ['status' => $rowSurat['status']],
                ['status' => $status_surat_baru, 'catatan' => $catatan],
                $admin_id
            );

            $flash = [
                'type' => 'success',
                'message' => $decision === 'approve'
                    ? 'Surat berhasil disetujui.'
                    : 'Surat berhasil ditolak.',
            ];
        } catch (Exception $e) {
            $conn->rollBack();
            $flash = ['type' => 'danger', 'message' => 'Gagal memproses: ' . $e->getMessage()];
        }
    }

    $_SESSION['approval_flash'] = $flash;
    $redirect_status_surat = $_GET['status_surat'] ?? '';
    header("Location: approval.php?tab=surat" . ($redirect_status_surat !== '' ? '&status_surat=' . urlencode($redirect_status_surat) : ''));
    exit;
}

$flash = $_SESSION['approval_flash'] ?? $flash;
unset($_SESSION['approval_flash']);

// ================== FILTER STATUS ==================
$status_filter = $_GET['status'] ?? 'Menunggu Verifikasi';
$valid_statuses = ['Menunggu Verifikasi', 'Diverifikasi', 'Dijadwalkan', 'Ditolak', 'Selesai', 'Semua'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'Menunggu Verifikasi';
}

$counts = [
    'Menunggu Verifikasi' => 0,
    'Diverifikasi'        => 0,
    'Ditolak'             => 0,
    'Selesai'             => 0,
];
try {
    $stmtCount = $conn->query("SELECT status, COUNT(*) AS jumlah FROM Pengajuan_Pemeriksaan GROUP BY status");
    foreach ($stmtCount->fetchAll() as $c) {
        if (isset($counts[$c['status']])) {
            $counts[$c['status']] = (int) $c['jumlah'];
        }
    }
} catch (PDOException $e) {
    // biarkan default 0 jika query gagal
}

// ================== AMBIL DAFTAR PENGAJUAN (harus dijalankan DULUAN) ==================
$daftar_pengajuan = [];
try {
    $sql = "
        SELECT pp.*, dk.nama_perusahaan AS nama_perusahaan_klien, dk.kode_klien, u.nama_lengkap AS nama_pengaju
        FROM Pengajuan_Pemeriksaan pp
        LEFT JOIN Data_Klien dk ON pp.klien_id = dk.id
        LEFT JOIN Users u ON pp.diajukan_oleh = u.id
    ";
    $params = [];
    if ($status_filter !== 'Semua') {
        $sql .= " WHERE pp.status = :status ";
        $params[':status'] = $status_filter;
    }
    $sql .= " ORDER BY pp.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $daftar_pengajuan = $stmt->fetchAll();
} catch (PDOException $e) {
    $daftar_pengajuan = [];
}

// ================== AMBIL SEMUA UNIT PER PENGAJUAN (1 pengajuan bisa banyak unit) ==================
$unit_per_pengajuan = [];
if (!empty($daftar_pengajuan)) {
    try {
        $ids = array_column($daftar_pengajuan, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtUnit = $conn->prepare("
            SELECT pu.id, pu.pengajuan_id, pu.nama_unit, pu.jenis_pemeriksaan, k.nama_kategori AS bidang
            FROM Pengajuan_Pemeriksaan_Unit pu
            LEFT JOIN jenis_objek_k3 j ON j.id_jenis = pu.id_jenis
            LEFT JOIN kategori_objek_k3 k ON k.id_kategori = j.id_kategori
            WHERE pu.pengajuan_id IN ($placeholders)
            ORDER BY pu.pengajuan_id ASC, pu.urutan ASC, pu.id ASC
        ");
        $stmtUnit->execute($ids);
        foreach ($stmtUnit->fetchAll() as $u) {
            $unit_per_pengajuan[$u['pengajuan_id']][] = [
                'id'     => (int) $u['id'],
                'bidang' => $u['bidang'],
                'unit'   => $u['nama_unit'],
                'jenis'  => $u['jenis_pemeriksaan'],
            ];
        }
    } catch (PDOException $e) {
        $unit_per_pengajuan = [];
    }
}

// ================== AMBIL SEMUA FILE/DOKUMEN PENDUKUNG PER PENGAJUAN ==================
// File yang diunggah client saat mengajukan (client/pengajuan.php) disimpan di
// Dokumen_Digital dengan modul_sumber = 'Pengajuan_Pemeriksaan' dan ref_id = id pengajuan.
// Diambil di sini supaya Admin bisa melihat & (kalau perlu) mengganti file per pengajuan
// lewat tabel file di halaman approval, tanpa mengubah data pengajuan lainnya.
$dokumen_per_pengajuan = [];
if (!empty($daftar_pengajuan)) {
    try {
        $idsDok = array_column($daftar_pengajuan, 'id');
        $placeholdersDok = implode(',', array_fill(0, count($idsDok), '?'));
        $stmtDok = $conn->prepare("
            SELECT id, ref_id, nama_dokumen, file_path, drive_file_id, created_at
            FROM Dokumen_Digital
            WHERE modul_sumber = 'Pengajuan_Pemeriksaan' AND ref_id IN ($placeholdersDok)
            ORDER BY ref_id ASC, created_at ASC, id ASC
        ");
        $stmtDok->execute($idsDok);
        foreach ($stmtDok->fetchAll() as $d) {
            $dokumen_per_pengajuan[$d['ref_id']][] = [
                'id'            => (int) $d['id'],
                'nama_dokumen'  => $d['nama_dokumen'],
                'file_path'     => $d['file_path'],
                'drive_file_id' => $d['drive_file_id'],
                'created_at'    => $d['created_at'],
            ];
        }
    } catch (PDOException $e) {
        $dokumen_per_pengajuan = [];
    }
}

function ambil_unit_pengajuan(array $p, array $unit_per_pengajuan): array
{
    if (!empty($unit_per_pengajuan[$p['id']])) {
        return $unit_per_pengajuan[$p['id']];
    }
    return [[
        'id'     => 0,
        'bidang' => $p['klasifikasi_objek_k3'] ?? null,
        'unit'   => $p['jenis_objek'] ?? null,
        'jenis'  => $p['jenis_pemeriksaan'] ?? null,
    ]];
}

function kelompokkan_unit_per_bidang(array $unit_list): array
{
    $grup = [];
    foreach ($unit_list as $u) {
        $namaBidang = (!empty($u['bidang'])) ? $u['bidang'] : 'Belum Ada Bidang';
        if (!isset($grup[$namaBidang])) {
            $grup[$namaBidang] = [];
        }
        $grup[$namaBidang][] = [
            'unit'  => $u['unit'] ?? '-',
            'jenis' => $u['jenis'] ?? null,
        ];
    }
    return $grup;
}

function get_enum_values(PDO $conn, string $table, string $column): array
{
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
        $stmt->execute([':column' => $column]);
        $col = $stmt->fetch();
        if (!$col || !preg_match("/^enum\((.*)\)$/i", $col['Type'], $matches)) {
            return [];
        }
        return str_getcsv($matches[1], ',', "'");
    } catch (PDOException $e) {
        return [];
    }
}

// ================== DATA PENDUKUNG UNTUK MODAL EDIT PENGAJUAN ==================
$daftar_users_pengaju = [];
try {
    $stmtUsers = $conn->query("SELECT id, nama_lengkap, role FROM Users ORDER BY nama_lengkap ASC");
    $daftar_users_pengaju = $stmtUsers->fetchAll();
} catch (PDOException $e) {
    $daftar_users_pengaju = [];
}

$daftar_jenis_pemeriksaan_opsi = get_enum_values($conn, 'Pengajuan_Pemeriksaan_Unit', 'jenis_pemeriksaan');
if (empty($daftar_jenis_pemeriksaan_opsi)) {
    $daftar_jenis_pemeriksaan_opsi = ['Pemeriksaan Baru', 'Pemeriksaan Berkala', 'Pemeriksaan Ulang', 'Pemeriksaan Khusus'];
}

function singkat_jenis_pemeriksaan(?string $jenis): string
{
    $map = [
        'Pemeriksaan Baru'    => 'Baru',
        'Pemeriksaan Berkala' => 'Berkala',
        'Pemeriksaan Ulang'   => 'Ulang',
        'Pemeriksaan Khusus'  => 'Khusus',
    ];
    return $map[$jenis] ?? ($jenis ?? '');
}

function badge_class_status(string $status): string
{
    switch ($status) {
        case 'Menunggu Verifikasi':
            return 'badge-warning';
        case 'Diverifikasi':
        case 'Dijadwalkan':
            return 'badge-info';
        case 'Selesai':
            return 'badge-success';
        case 'Ditolak':
            return 'badge-danger';
        default:
            return 'badge-secondary';
    }
}

// ================== FILTER STATUS SURAT ==================
$status_filter_surat = $_GET['status_surat'] ?? 'Diajukan';
$valid_statuses_surat = ['Diajukan', 'Disetujui', 'Ditolak', 'Draft', 'Semua'];
if (!in_array($status_filter_surat, $valid_statuses_surat, true)) {
    $status_filter_surat = 'Diajukan';
}

$counts_surat = [
    'Diajukan'  => 0,
    'Disetujui' => 0,
    'Ditolak'   => 0,
    'Draft'     => 0,
];
try {
    $stmtCountSurat = $conn->query("SELECT status, COUNT(*) AS jumlah FROM surat GROUP BY status");
    foreach ($stmtCountSurat->fetchAll() as $c) {
        if (isset($counts_surat[$c['status']])) {
            $counts_surat[$c['status']] = (int) $c['jumlah'];
        }
    }
} catch (PDOException $e) {
    // biarkan default 0 jika query gagal
}

// ================== AMBIL DAFTAR SURAT ==================
$daftar_surat = [];
try {
    $sqlSurat = "
        SELECT s.*, u.nama_lengkap AS nama_pembuat, u.role AS role_pembuat, ks.nama AS nama_jenis_surat
        FROM surat s
        LEFT JOIN Users u ON s.dibuat_oleh = u.id
        LEFT JOIN kode_surat ks ON s.kode_id = ks.id
    ";
    $paramsSurat = [];
    if ($status_filter_surat !== 'Semua') {
        $sqlSurat .= " WHERE s.status = :status ";
        $paramsSurat[':status'] = $status_filter_surat;
    }
    $sqlSurat .= " ORDER BY s.created_at DESC";

    $stmtSurat = $conn->prepare($sqlSurat);
    $stmtSurat->execute($paramsSurat);
    $daftar_surat = $stmtSurat->fetchAll();
} catch (PDOException $e) {
    $daftar_surat = [];
}

function badge_class_status_surat(string $status): string
{
    switch ($status) {
        case 'Diajukan':
            return 'badge-warning';
        case 'Disetujui':
            return 'badge-success';
        case 'Ditolak':
            return 'badge-danger';
        case 'Draft':
        default:
            return 'badge-secondary';
    }
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<style>
.table-custom {
    table-layout: fixed;
    width: 100%;
}
.table-custom colgroup col.col-no       { width: 44px; }
.table-custom colgroup col.col-tanggal  { width: 110px; }
.table-custom colgroup col.col-perusahaan { width: 190px; }
.table-custom colgroup col.col-pengaju  { width: 130px; }
.table-custom colgroup col.col-unit     { width: 240px; }
.table-custom colgroup col.col-jenis    { width: 140px; }
/* Kolom Tgl. Diinginkan dilebarkan supaya muat tanggal + tombol ubah tanggal berdampingan */
.table-custom colgroup col.col-tgldiinginkan { width: 170px; }
.table-custom colgroup col.col-file     { width: 110px; }
.table-custom colgroup col.col-status   { width: 110px; }

/* Tombol ikon file (memicu modal pilih file mana yang mau dilihat/diganti) */
.file-trigger-icon-btn {
    position: relative;
    width: 38px;
    height: 38px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}
.file-trigger-icon-btn:hover {
    background: #eef2ff;
    border-color: #c7d2fe;
    color: #4338ca;
}
.file-trigger-icon-btn i {
    font-size: 1.25rem;
}
.file-trigger-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 18px;
    height: 18px;
    padding: 0 4px;
    border-radius: 999px;
    background: var(--primary, #4f46e5);
    color: #fff;
    font-size: 0.66rem;
    font-weight: 700;
    line-height: 18px;
    text-align: center;
}
.table-custom colgroup col.col-aksi     { width: 210px; }

.table-custom th,
.table-custom td {
    vertical-align: top;
    padding: 12px 14px;
    word-wrap: break-word;
    overflow-wrap: break-word;
}
.table-custom td.align-middle {
    vertical-align: middle;
}

.cell-perusahaan .nama-utama {
    font-weight: 600;
    color: #1e293b;
    line-height: 1.3;
}
.cell-perusahaan .sub-info {
    font-size: 0.74rem;
    color: #94a3b8;
    line-height: 1.4;
    margin-top: 2px;
}

.bidang-group {
    background: #f8fafc;
    border: 1px solid #eef1f5;
    border-radius: 8px;
    padding: 8px 10px;
}
.bidang-group + .bidang-group {
    margin-top: 6px;
}
.bidang-group-title {
    font-weight: 700;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: #64748b;
    margin-bottom: 5px;
}
.bidang-group-list {
    margin: 0;
    padding: 0;
    list-style: none;
}
.bidang-group-list li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    font-size: 0.8rem;
    color: #334155;
    padding: 2px 0;
}
.bidang-group-list li:not(:last-child) {
    border-bottom: 1px dashed #e2e8f0;
}
.bidang-group-list .unit-name {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.unit-count-note {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-top: 6px;
}

.badge-jenis-unit {
    flex-shrink: 0;
    display: inline-block;
    font-size: 0.66rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    white-space: nowrap;
}
.badge-jenis-unit.jenis-baru    { background: #eef2ff; color: #4338ca; }
.badge-jenis-unit.jenis-berkala { background: #ecfdf5; color: #047857; }
.badge-jenis-unit.jenis-ulang   { background: #fff7ed; color: #c2410c; }
.badge-jenis-unit.jenis-khusus  { background: #fdf2f8; color: #be185d; }
.badge-jenis-unit.jenis-lainnya { background: #f1f5f9; color: #475569; }

.jenis-ringkasan {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

/* ---- Sel Tgl. Diinginkan: tanggal + tombol ubah berdampingan ---- */
.cell-tgl-diinginkan {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
.cell-tgl-diinginkan .tgl-text {
    white-space: nowrap;
}
.btn-ubah-tanggal {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #64748b;
    font-size: 0.78rem;
    transition: all .15s ease;
}
.btn-ubah-tanggal:hover {
    background: #eef2ff;
    border-color: #c7d2fe;
    color: #4338ca;
}

.aksi-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.aksi-wrapper .btn-primary-custom,
.aksi-wrapper .btn-secondary-custom {
    white-space: nowrap;
}

.table-custom tbody tr:nth-child(even) {
    background: #fafbfc;
}
.table-custom tbody tr:hover {
    background: #f1f5f9;
}
</style>

<main class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>-custom mb-3">
            <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
            <div><?= htmlspecialchars($flash['message']) ?></div>
        </div>
    <?php endif; ?>

    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $tab_aktif === 'pemeriksaan' ? ' active' : '' ?>"
                data-tab-target="tabPanelPemeriksaan" onclick="switchTab('tabPanelPemeriksaan', this)">
                <i class="bi bi-clipboard2-check me-1"></i> Pengajuan Pemeriksaan
            </button>
            <button type="button" class="arp-tab-btn<?= $tab_aktif === 'surat' ? ' active' : '' ?>"
                data-tab-target="tabPanelSurat" onclick="switchTab('tabPanelSurat', this)">
                <i class="bi bi-envelope-paper me-1"></i> Surat
            </button>
        </div>

        <!-- ============================== TAB: PENGAJUAN PEMERIKSAAN ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelPemeriksaan" <?= $tab_aktif === 'pemeriksaan' ? '' : 'style="display:none;"' ?>>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Menunggu Verifikasi</span>
                    <span class="stat-card-value"><?= $counts['Menunggu Verifikasi'] ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-hourglass-split"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Diverifikasi</span>
                    <span class="stat-card-value"><?= $counts['Diverifikasi'] ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-check2-square"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ditolak</span>
                    <span class="stat-card-value"><?= $counts['Ditolak'] ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-x-circle"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Selesai</span>
                    <span class="stat-card-value"><?= $counts['Selesai'] ?></span>
                </div>
                <div class="stat-card-icon success"><i class="bi bi-patch-check-fill"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Daftar Pengajuan Pemeriksaan</h5>
            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="tab" value="pemeriksaan">
                <select class="select-custom" name="status" style="width: 220px;" onchange="this.form.submit()">
                    <?php foreach (['Menunggu Verifikasi', 'Diverifikasi', 'Dijadwalkan', 'Ditolak', 'Selesai', 'Semua'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $status_filter === $opt ? 'selected' : '' ?>>
                            <?= $opt === 'Semua' ? 'Semua Status' : htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <colgroup>
                    <col class="col-no">
                    <col class="col-tanggal">
                    <col class="col-perusahaan">
                    <col class="col-pengaju">
                    <col class="col-unit">
                    <col class="col-jenis">
                    <col class="col-tgldiinginkan">
                    <col class="col-file">
                    <col class="col-status">
                    <col class="col-aksi">
                </colgroup>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tgl. Ajuan</th>
                        <th>Nama Perusahaan</th>
                        <th>Diajukan Oleh</th>
                        <th>Bidang &amp; Unit yang Diperiksa</th>
                        <th>Jenis Pemeriksaan</th>
                        <th>Tgl. Diinginkan</th>
                        <th style="text-align: center;">File</th>
                        <th>Status</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_pengajuan)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">Tidak ada data pengajuan untuk status ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftar_pengajuan as $i => $p): ?>
                            <?php
                                $unit_list    = ambil_unit_pengajuan($p, $unit_per_pengajuan);
                                $grup_bidang  = kelompokkan_unit_per_bidang($unit_list);
                                $jenis_unique = array_values(array_unique(array_filter(array_column($unit_list, 'jenis'))));
                                if (empty($jenis_unique) && !empty($p['jenis_pemeriksaan'])) {
                                    $jenis_unique = [$p['jenis_pemeriksaan']];
                                }

                                $kelas_jenis = function (?string $jenis): string {
                                    $map = [
                                        'Pemeriksaan Baru'    => 'jenis-baru',
                                        'Pemeriksaan Berkala' => 'jenis-berkala',
                                        'Pemeriksaan Ulang'   => 'jenis-ulang',
                                        'Pemeriksaan Khusus'  => 'jenis-khusus',
                                    ];
                                    return $map[$jenis] ?? 'jenis-lainnya';
                                };
                            ?>
                            <tr>
                                <td class="align-middle"><?= $i + 1 ?></td>
                                <td class="align-middle"><?= htmlspecialchars(date('d M Y', strtotime($p['created_at']))) ?></td>
                                <td class="cell-perusahaan align-middle">
                                    <div class="nama-utama"><?= htmlspecialchars($p['nama_perusahaan'] ?? '-') ?></div>
                                    <?php if (!empty($p['nama_perusahaan_klien']) && $p['nama_perusahaan_klien'] !== $p['nama_perusahaan']): ?>
                                        <div class="sub-info">Data Klien: <?= htmlspecialchars($p['nama_perusahaan_klien']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($p['kode_klien'])): ?>
                                        <div class="sub-info"><?= htmlspecialchars($p['kode_klien']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle"><?= htmlspecialchars($p['nama_pengaju'] ?? '-') ?></td>
                                <td>
                                    <?php foreach ($grup_bidang as $namaBidang => $daftarUnit): ?>
                                        <div class="bidang-group">
                                            <div class="bidang-group-title"><?= htmlspecialchars($namaBidang) ?></div>
                                            <ul class="bidang-group-list">
                                                <?php foreach ($daftarUnit as $u): ?>
                                                    <li>
                                                        <span class="unit-name" title="<?= htmlspecialchars($u['unit']) ?>"><?= htmlspecialchars($u['unit']) ?></span>
                                                        <?php if (!empty($u['jenis'])): ?>
                                                            <span class="badge-jenis-unit <?= $kelas_jenis($u['jenis']) ?>">
                                                                <?= htmlspecialchars(singkat_jenis_pemeriksaan($u['jenis'])) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($unit_list) > 1): ?>
                                        <div class="unit-count-note"><?= count($unit_list) ?> unit diajukan</div>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle">
                                    <?php if (empty($jenis_unique)): ?>
                                        <span class="text-muted fs-7">-</span>
                                    <?php else: ?>
                                        <div class="jenis-ringkasan">
                                            <?php foreach ($jenis_unique as $jn): ?>
                                                <span class="badge-jenis-unit <?= $kelas_jenis($jn) ?>">
                                                    <?= htmlspecialchars(singkat_jenis_pemeriksaan($jn)) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle">
                                    <span class="tgl-text"><?= $p['tanggal_diinginkan'] ? htmlspecialchars(date('d M Y', strtotime($p['tanggal_diinginkan']))) : '-' ?></span>
                                </td>
                                <td class="align-middle" style="text-align: center;">
                                    <?php
                                        $dokumen_list = $dokumen_per_pengajuan[$p['id']] ?? [];
                                        $file_payload = json_encode([
                                            'pengajuan_id'    => (int) $p['id'],
                                            'nama_perusahaan' => $p['nama_perusahaan'] ?? '-',
                                            'files'           => array_map(function ($d) {
                                                $url = $d['file_path'] ?? '';
                                                if ($url !== '' && !str_starts_with($url, 'http')) {
                                                    $url = '../' . ltrim($url, '/');
                                                }
                                                return [
                                                    'id'      => $d['id'],
                                                    'nama'    => $d['nama_dokumen'],
                                                    'url'     => $url,
                                                    'tanggal' => $d['created_at'] ? date('d M Y H:i', strtotime($d['created_at'])) : '-',
                                                ];
                                            }, $dokumen_list),
                                        ], JSON_UNESCAPED_UNICODE);
                                    ?>
                                    <button type="button" class="file-trigger-icon-btn"
                                        title="Lihat file yang dikirim client (<?= count($dokumen_list) ?> file)"
                                        data-files='<?= htmlspecialchars($file_payload, ENT_QUOTES, 'UTF-8') ?>'
                                        onclick="openFilePengajuanModal(this)">
                                        <i class="bi bi-paperclip"></i>
                                        <?php if (count($dokumen_list) > 0): ?>
                                            <span class="file-trigger-badge"><?= count($dokumen_list) ?></span>
                                        <?php endif; ?>
                                    </button>
                                </td>
                                <td class="align-middle"><span class="<?= badge_class_status($p['status']) ?>"><?= htmlspecialchars($p['status']) ?></span></td>
                                <td class="align-middle" style="text-align: center;">
                                    <?php
                                        $edit_payload = json_encode([
                                            'id'                 => (int) $p['id'],
                                            'nama_perusahaan'    => $p['nama_perusahaan'] ?? '',
                                            'diajukan_oleh'      => (int) ($p['diajukan_oleh'] ?? 0),
                                            'tanggal_diinginkan' => $p['tanggal_diinginkan'] ?? '',
                                            'units'              => array_map(function ($u) {
                                                return [
                                                    'id'    => (int) ($u['id'] ?? 0),
                                                    'nama'  => $u['unit'] ?? '',
                                                    'jenis' => $u['jenis'] ?? '',
                                                ];
                                            }, $unit_list),
                                        ], JSON_UNESCAPED_UNICODE);
                                    ?>
                                    <div class="aksi-wrapper">
                                        <button type="button" class="btn-secondary-custom btn-edit-pengajuan" style="height:32px; padding:0 12px; font-size:0.8rem;"
                                            title="Edit Pengajuan"
                                            data-edit='<?= htmlspecialchars($edit_payload, ENT_QUOTES, 'UTF-8') ?>'
                                            onclick="openEditPengajuanModal(this)">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <?php if ($p['status'] === 'Menunggu Verifikasi'): ?>
                                            <button type="button" class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;"
                                                onclick="openApprovalModal(<?= (int) $p['id'] ?>, 'approve', '<?= htmlspecialchars(addslashes($p['nama_perusahaan'] ?? '-')) ?>')">
                                                <i class="bi bi-check-lg"></i> Setujui
                                            </button>
                                            <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 12px; font-size:0.8rem; color: var(--danger); border-color: var(--danger);"
                                                onclick="openApprovalModal(<?= (int) $p['id'] ?>, 'reject', '<?= htmlspecialchars(addslashes($p['nama_perusahaan'] ?? '-')) ?>')">
                                                <i class="bi bi-x-lg"></i> Tolak
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted fs-7 d-block w-100 mt-1">
                                                <?= !empty($p['catatan_admin']) ? htmlspecialchars($p['catatan_admin']) : '-' ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

        </div>

        <!-- ============================== TAB: SURAT ============================== -->
        <div class="col-12 arp-tab-panel" id="tabPanelSurat" <?= $tab_aktif === 'surat' ? '' : 'style="display:none;"' ?>>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Diajukan</span>
                    <span class="stat-card-value"><?= $counts_surat['Diajukan'] ?></span>
                </div>
                <div class="stat-card-icon warning"><i class="bi bi-hourglass-split"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Disetujui</span>
                    <span class="stat-card-value"><?= $counts_surat['Disetujui'] ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-check2-square"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ditolak</span>
                    <span class="stat-card-value"><?= $counts_surat['Ditolak'] ?></span>
                </div>
                <div class="stat-card-icon danger"><i class="bi bi-x-circle"></i></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Draft</span>
                    <span class="stat-card-value"><?= $counts_surat['Draft'] ?></span>
                </div>
                <div class="stat-card-icon"><i class="bi bi-file-earmark-text"></i></div>
            </div>
        </div>
    </div>

    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Daftar Persetujuan Surat</h5>
            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="tab" value="surat">
                <select class="select-custom" name="status_surat" style="width: 220px;" onchange="this.form.submit()">
                    <?php foreach (['Diajukan', 'Disetujui', 'Ditolak', 'Draft', 'Semua'] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $status_filter_surat === $opt ? 'selected' : '' ?>>
                            <?= $opt === 'Semua' ? 'Semua Status' : htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <colgroup>
                    <col style="width: 44px;">
                    <col style="width: 110px;">
                    <col style="width: 140px;">
                    <col style="width: 220px;">
                    <col style="width: 150px;">
                    <col style="width: 130px;">
                    <col style="width: 110px;">
                    <col style="width: 170px;">
                    <col style="width: 110px;">
                </colgroup>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tgl. Dibuat</th>
                        <th>Nomor Surat</th>
                        <th>Perihal</th>
                        <th>Jenis Surat</th>
                        <th>Dibuat Oleh</th>
                        <th>Status</th>
                        <th style="text-align: center;">Tindakan</th>
                        <th style="text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daftar_surat)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Tidak ada data surat untuk status ini.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($daftar_surat as $i => $s): ?>
                            <tr>
                                <td class="align-middle"><?= $i + 1 ?></td>
                                <td class="align-middle"><?= htmlspecialchars(date('d M Y', strtotime($s['tgl_dibuat'] ?? $s['created_at']))) ?></td>
                                <td class="align-middle"><?= htmlspecialchars($s['nomor'] ?? '-') ?></td>
                                <td class="align-middle"><?= htmlspecialchars($s['perihal'] ?? '-') ?></td>
                                <td class="align-middle"><?= htmlspecialchars($s['nama_jenis_surat'] ?? ($s['jenis_surat'] ?? '-')) ?></td>
                                <td class="align-middle">
                                    <strong><?= htmlspecialchars($s['nama_pembuat'] ?? '-') ?></strong>
                                    <br><small class="text-secondary"><?= htmlspecialchars(ucfirst($s['role_pembuat'] ?? '-')) ?></small>
                                </td>
                                <td class="align-middle"><span class="<?= badge_class_status_surat($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></span></td>
                                <td class="align-middle" style="text-align: center;">
                                    <?php if ($s['status'] === 'Diajukan'): ?>
                                        <div class="aksi-wrapper">
                                            <button type="button" class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;"
                                                onclick="openApprovalModalSurat(<?= (int) $s['id'] ?>, 'approve', '<?= htmlspecialchars(addslashes($s['perihal'] ?? '-')) ?>')">
                                                <i class="bi bi-check-lg"></i> Setujui
                                            </button>
                                            <button type="button" class="btn-secondary-custom" style="height:32px; padding:0 12px; font-size:0.8rem; color: var(--danger); border-color: var(--danger);"
                                                onclick="openApprovalModalSurat(<?= (int) $s['id'] ?>, 'reject', '<?= htmlspecialchars(addslashes($s['perihal'] ?? '-')) ?>')">
                                                <i class="bi bi-x-lg"></i> Tolak
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted fs-7">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle" style="text-align: center;">
                                    <?php if (!empty($s['file_hasil'])):
                                        $hrefFileSurat = str_starts_with($s['file_hasil'], 'http') ? $s['file_hasil'] : '../' . $s['file_hasil'];
                                    ?>
                                        <button type="button" class="btn-icon-bukti"
                                            onclick="openFileModal('<?= htmlspecialchars($hrefFileSurat, ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($s['nomor'] ?? $s['perihal'] ?? 'Surat'), ENT_QUOTES) ?>')"
                                            title="Lihat File">
                                            <i class="bi bi-paperclip"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted fs-7">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

        </div>

    </div>
</main>

<div class="modal fade modal-custom" id="modalApproval" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="approval.php<?= $status_filter !== 'Menunggu Verifikasi' ? '?status=' . urlencode($status_filter) : '' ?>" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalApprovalTitle">Setujui Pengajuan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Perusahaan: <strong id="modalApprovalNamaKlien">-</strong></p>

                    <input type="hidden" name="pengajuan_id" id="modalApprovalPengajuanId" value="">
                    <input type="hidden" name="decision" id="modalApprovalDecision" value="">

                    <label class="form-label fw-semibold fs-7 mb-2" id="modalApprovalCatatanLabel">Catatan (opsional)</label>
                    <textarea class="textarea-custom" name="catatan" id="modalApprovalCatatan" placeholder="Tulis catatan untuk klien/tim internal..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="proses_approval" class="btn-primary-custom" id="modalApprovalSubmit">Setujui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Pengajuan (semua kolom) -->
<div class="modal fade modal-custom" id="modalEditPengajuan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form action="approval.php<?= $status_filter !== 'Menunggu Verifikasi' ? '?status=' . urlencode($status_filter) : '' ?>" method="POST" id="formEditPengajuan">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Pengajuan Pemeriksaan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="pengajuan_id" id="editPengajuanId" value="">
                    <input type="hidden" name="unit_hapus" id="editUnitHapus" value="">

                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama Perusahaan</label>
                            <input type="text" class="form-control-custom" name="nama_perusahaan" id="editNamaPerusahaan" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold fs-7 mb-2">Diajukan Oleh</label>
                            <select class="select-custom w-100" name="diajukan_oleh" id="editDiajukanOleh" required>
                                <?php foreach ($daftar_users_pengaju as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['nama_lengkap']) ?> (<?= htmlspecialchars($u['role']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7 mb-2">Tanggal Diinginkan</label>
                        <div class="date-input-wrapper">
                            <i class="bi bi-calendar-week"></i>
                            <input type="date" class="form-control-custom" name="tanggal_diinginkan" id="editTanggalDiinginkan" required>
                        </div>
                    </div>

                    <div class="mb-2 d-flex align-items-center justify-content-between">
                        <label class="form-label fw-semibold fs-7 mb-0">Bidang / Unit yang Diperiksa</label>
                        <button type="button" class="btn-secondary-custom" style="height:30px; padding:0 10px; font-size:0.78rem;" onclick="tambahBarisUnitEdit()">
                            <i class="bi bi-plus-lg"></i> Tambah Unit
                        </button>
                    </div>
                    <div id="editUnitRows"></div>
                    <div class="form-text fs-8 text-muted mt-1">
                        Kategori/Bidang unit mengikuti data master dan tidak berubah otomatis saat nama unit diedit bebas.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="edit_pengajuan" class="btn-primary-custom">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Approval Surat -->
<div class="modal fade modal-custom" id="modalApprovalSurat" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="approval.php?tab=surat<?= $status_filter_surat !== 'Diajukan' ? '&status_surat=' . urlencode($status_filter_surat) : '' ?>" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalApprovalSuratTitle">Setujui Surat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Perihal: <strong id="modalApprovalSuratPerihal">-</strong></p>

                    <input type="hidden" name="surat_id" id="modalApprovalSuratId" value="">
                    <input type="hidden" name="decision" id="modalApprovalSuratDecision" value="">

                    <label class="form-label fw-semibold fs-7 mb-2" id="modalApprovalSuratCatatanLabel">Catatan (opsional)</label>
                    <textarea class="textarea-custom" name="catatan" id="modalApprovalSuratCatatan" placeholder="Tulis catatan untuk pembuat surat..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="proses_approval_surat" class="btn-primary-custom" id="modalApprovalSuratSubmit">Setujui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Lihat File -->
<div class="modal fade modal-custom" id="modalLihatFile" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalLihatFileTitle">Lampiran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="modalLihatFileBody" style="min-height: 200px;">
                <!-- diisi via JS -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-primary-custom" id="modalLihatFilePrint" style="display:none;">
                    <i class="bi bi-printer"></i> Cetak
                </button>
                <a href="#" id="modalLihatFileDownload" target="_blank" rel="noopener noreferrer" class="btn-secondary-custom"
                    onclick="return bukaTabBaruDenganFokus(this.getAttribute('data-url'))">
                    <i class="bi bi-box-arrow-up-right"></i> Buka di Tab Baru
                </a>
                <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
// Google Drive punya endpoint khusus untuk di-embed di iframe (.../preview) yang
// TIDAK melakukan frame-busting. Link ".../view" biasa (hasil upload) akan mendeteksi
// dirinya sedang di-frame dan otomatis window.open() dirinya sendiri ke tab baru --
// itulah yang membuat tab baru muncul di background saat file Drive dibuka di modal.
function ambilFileIdDrive(url) {
    if (!url) return null;
    let m = url.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
    if (m) return m[1];
    m = url.match(/[?&]id=([a-zA-Z0-9_-]+)/);
    if (m) return m[1];
    return null;
}

function urlEmbedAmanUntukIframe(url) {
    const fileId = ambilFileIdDrive(url);
    if (fileId) {
        return 'https://drive.google.com/file/d/' + fileId + '/preview';
    }
    return url;
}

// Link Google Drive tidak selalu diakhiri ".pdf" di URL-nya, jadi deteksi ekstensi
// juga dicoba dari nama file asli (label) kalau dari URL tidak ketemu.
function tentukanEkstensiFile(fileUrl, label) {
    const extValid = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    const dariUrl = fileUrl.split('?')[0].split('.').pop().toLowerCase();
    if (extValid.includes(dariUrl)) return dariUrl;
    if (label) {
        const dariLabel = label.split('.').pop().toLowerCase();
        if (extValid.includes(dariLabel)) return dariLabel;
    }
    return dariUrl;
}

function openFileModal(fileUrl, label) {
    const body = document.getElementById('modalLihatFileBody');
    const title = document.getElementById('modalLihatFileTitle');
    const downloadBtn = document.getElementById('modalLihatFileDownload');
    const printBtn = document.getElementById('modalLihatFilePrint');

    title.textContent = 'Lampiran' + (label ? ' - ' + label : '');
    downloadBtn.href = fileUrl;
    downloadBtn.setAttribute('data-url', fileUrl);
    printBtn.onclick = null;
    printBtn.style.display = 'none';

    const ext = tentukanEkstensiFile(fileUrl, label);
    const gambarExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    if (gambarExt.includes(ext)) {
        body.innerHTML = `<img id="modalLihatFileImg" src="${fileUrl}" alt="Lampiran" style="max-width:100%; height:auto; display:block; margin:0 auto;">`;
        printBtn.style.display = 'inline-flex';
        printBtn.onclick = function () { cetakGambarLampiran(fileUrl); };
    } else if (ext === 'pdf') {
        const embedUrl = urlEmbedAmanUntukIframe(fileUrl);
        body.innerHTML = `<iframe id="modalLihatFileFrame" src="${embedUrl}" style="width:100%; height:70vh; border:0;"></iframe>`;
        printBtn.style.display = 'inline-flex';
        printBtn.onclick = function () { cetakPdfLampiran(fileUrl); };
    } else {
        body.innerHTML = `
            <div class="text-center py-4">
                <i class="bi bi-file-earmark-text" style="font-size:2.5rem;"></i>
                <p class="text-secondary mt-2 mb-0">Pratinjau tidak tersedia untuk tipe berkas ini (mis. Word).<br>Silakan gunakan tombol "Buka di Tab Baru", lalu cetak dari aplikasi/pembacanya (mis. Word, Google Docs).</p>
            </div>`;
    }

    const modal = new bootstrap.Modal(document.getElementById('modalLihatFile'));
    modal.show();
}

function cetakPdfLampiran(fileUrlAsli) {
    const frame = document.getElementById('modalLihatFileFrame');
    // Iframe Drive (.../preview) berbeda origin -> contentWindow tidak bisa diakses
    // untuk print langsung. Fallback: buka link aslinya di tab baru (dengan fokus).
    try {
        if (frame && frame.contentWindow && frame.src.indexOf('drive.google.com') === -1) {
            frame.contentWindow.focus();
            frame.contentWindow.print();
            return;
        }
    } catch (e) {
        // lanjut ke fallback di bawah
    }
    bukaTabBaruDenganFokus(fileUrlAsli || (frame ? frame.src : ''));
}

function bukaTabBaruDenganFokus(url) {
    if (!url || url === '#') return false;
    const winBaru = window.open(url, '_blank', 'noopener,noreferrer');
    if (winBaru) {
        winBaru.focus();
    }
    return false;
}

function cetakGambarLampiran(fileUrl) {
    const jendelaCetak = window.open('', '_blank', 'width=800,height=600,noopener,noreferrer');
    if (!jendelaCetak) return;
    jendelaCetak.document.write(`
        <html>
        <head><title>Cetak Lampiran</title></head>
        <body style="margin:0; display:flex; justify-content:center; align-items:center;">
            <img src="${fileUrl}" style="max-width:100%;" onload="window.print();">
        </body>
        </html>
    `);
    jendelaCetak.document.close();
    jendelaCetak.focus();
}
</script>

<!-- ============================== MODAL: FILE PENGAJUAN (dikirim client) ============================== -->
<div class="modal fade modal-custom" id="modalFilePengajuan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">File Pendukung - <span id="modalFilePengajuanJudul">-</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary fs-7 mb-3">
                    Klik ikon file untuk melihat. Untuk mengganti berkas yang salah/kurang jelas, klik ikon <i class="bi bi-arrow-repeat"></i>
                    pada file yang ingin diperbarui — hanya file tersebut yang akan diubah, file lain pada pengajuan ini tidak ikut terpengaruh.
                </p>
                <div id="modalFilePengajuanGrid" class="file-logo-grid">
                    <!-- diisi via JS -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<style>
.file-logo-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}
.file-logo-item {
    position: relative;
    width: 120px;
    text-align: center;
}
.file-logo-icon-wrap {
    position: relative;
    width: 88px;
    height: 88px;
    margin: 0 auto 6px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}
.file-logo-icon-wrap:hover {
    background: #eef2ff;
    border-color: #c7d2fe;
}
.file-logo-icon-wrap i.bi-file-earmark-pdf   { color: #dc2626; }
.file-logo-icon-wrap i.bi-file-earmark-image { color: #2563eb; }
.file-logo-icon-wrap i.bi-file-earmark       { color: #64748b; }
.file-logo-icon-wrap i {
    font-size: 2.6rem;
}
.file-logo-ganti-btn {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #fff;
    border: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.85rem;
    color: #475569;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
}
.file-logo-ganti-btn:hover {
    background: var(--primary, #4f46e5);
    color: #fff;
    border-color: var(--primary, #4f46e5);
}
.file-logo-name {
    font-size: 0.76rem;
    color: #334155;
    word-break: break-word;
    line-height: 1.3;
    max-height: 2.6em;
    overflow: hidden;
}
.file-logo-tanggal {
    font-size: 0.68rem;
    color: #94a3b8;
    margin-top: 2px;
}
</style>

<script>
function escapeHtmlText(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function iconClassUntukFile(nama) {
    const ext = (nama || '').split('.').pop().toLowerCase();
    if (ext === 'pdf') return 'bi-file-earmark-pdf';
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext)) return 'bi-file-earmark-image';
    return 'bi-file-earmark';
}

function openFilePengajuanModal(btn) {
    const data = JSON.parse(btn.getAttribute('data-files'));
    document.getElementById('modalFilePengajuanJudul').textContent = data.nama_perusahaan || '-';

    const grid = document.getElementById('modalFilePengajuanGrid');
    grid.innerHTML = '';

    if (!data.files || data.files.length === 0) {
        grid.innerHTML = '<div class="text-center text-muted py-3 w-100">Client belum mengirim file pendukung untuk pengajuan ini.</div>';
    } else {
        data.files.forEach(function (f) {
            const item = document.createElement('div');
            item.className = 'file-logo-item';
            item.innerHTML =
                '<div class="file-logo-icon-wrap" title="Lihat file" ' +
                    'onclick="openFileModal(\'' + String(f.url).replace(/'/g, "\\'") + '\', \'' + String(f.nama).replace(/'/g, "\\'") + '\')">' +
                    '<i class="bi ' + iconClassUntukFile(f.nama) + '"></i>' +
                    '<label class="file-logo-ganti-btn" title="Ganti file ini" onclick="event.stopPropagation();">' +
                        '<i class="bi bi-arrow-repeat"></i>' +
                        '<input type="file" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" ' +
                            'onchange="gantiFilePengajuan(' + f.id + ', ' + data.pengajuan_id + ', this)">' +
                    '</label>' +
                '</div>' +
                '<div class="file-logo-name" title="' + escapeHtmlText(f.nama) + '">' + escapeHtmlText(f.nama) + '</div>' +
                '<div class="file-logo-tanggal">' + escapeHtmlText(f.tanggal) + '</div>';
            grid.appendChild(item);
        });
    }

    const modal = new bootstrap.Modal(document.getElementById('modalFilePengajuan'));
    modal.show();
}


// Mengirim HANYA file yang dipilih untuk diganti (dokumenId) -- tidak mengirim ulang
// atau mengubah file lain maupun data pengajuan lainnya.
function gantiFilePengajuan(dokumenId, pengajuanId, inputEl) {
    if (!inputEl.files || !inputEl.files[0]) return;

    const namaFileBaru = inputEl.files[0].name;
    if (!confirm('Ganti file ini dengan "' + namaFileBaru + '"? File lama akan digantikan.')) {
        inputEl.value = '';
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'approval.php' + window.location.search;
    form.enctype = 'multipart/form-data';
    form.style.display = 'none';

    function addHidden(name, value) {
        const el = document.createElement('input');
        el.type = 'hidden';
        el.name = name;
        el.value = value;
        form.appendChild(el);
    }
    addHidden('ganti_file_pengajuan', '1');
    addHidden('dokumen_id', dokumenId);
    addHidden('pengajuan_id', pengajuanId);

    // Pindahkan input file yang sudah berisi file terpilih ke dalam form baru ini,
    // supaya hanya 1 file (yang mau diganti) yang ikut terkirim.
    form.appendChild(inputEl);

    document.body.appendChild(form);
    form.submit();
}
</script>


<script>
function openApprovalModal(pengajuanId, decision, namaKlien) {
    document.getElementById('modalApprovalPengajuanId').value = pengajuanId;
    document.getElementById('modalApprovalDecision').value = decision;
    document.getElementById('modalApprovalNamaKlien').textContent = namaKlien;

    const title = document.getElementById('modalApprovalTitle');
    const submitBtn = document.getElementById('modalApprovalSubmit');
    const catatanLabel = document.getElementById('modalApprovalCatatanLabel');
    const catatanInput = document.getElementById('modalApprovalCatatan');
    catatanInput.value = '';

    if (decision === 'approve') {
        title.textContent = 'Setujui Pengajuan';
        submitBtn.textContent = 'Setujui';
        catatanLabel.textContent = 'Catatan (opsional)';
        catatanInput.required = false;
    } else {
        title.textContent = 'Tolak Pengajuan';
        submitBtn.textContent = 'Tolak Pengajuan';
        catatanLabel.textContent = 'Alasan Penolakan (wajib diisi)';
        catatanInput.required = true;
    }

    const modal = new bootstrap.Modal(document.getElementById('modalApproval'));
    modal.show();
}

// ================== EDIT PENGAJUAN (semua kolom) ==================
const jenisPemeriksaanOptions = <?= json_encode($daftar_jenis_pemeriksaan_opsi, JSON_UNESCAPED_UNICODE) ?>;

function escapeHtmlAttr(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function buatOpsiJenisPemeriksaan(selected) {
    let html = '<option value="">- Jenis Pemeriksaan -</option>';
    jenisPemeriksaanOptions.forEach(function (opt) {
        html += '<option value="' + escapeHtmlAttr(opt) + '"' + (opt === selected ? ' selected' : '') + '>' + escapeHtmlAttr(opt) + '</option>';
    });
    return html;
}

function buatBarisUnitEdit(unit) {
    unit = unit || { id: 0, nama: '', jenis: '' };
    const wrapper = document.createElement('div');
    wrapper.className = 'edit-unit-row d-flex gap-2 align-items-start mb-2';
    wrapper.innerHTML =
        '<input type="hidden" name="unit_id[]" value="' + (unit.id || 0) + '">' +
        '<input type="text" class="form-control-custom" name="unit_nama[]" placeholder="Nama Unit / Objek" style="flex:2;" value="' + escapeHtmlAttr(unit.nama) + '" required>' +
        '<select class="select-custom" name="unit_jenis[]" style="flex:1;">' + buatOpsiJenisPemeriksaan(unit.jenis) + '</select>' +
        '<button type="button" class="btn-secondary-custom" style="height:38px; padding:0 10px; color:var(--danger); border-color:var(--danger);" ' +
        'onclick="hapusBarisUnitEdit(this, ' + (unit.id || 0) + ')" title="Hapus unit ini"><i class="bi bi-trash"></i></button>';
    return wrapper;
}

function tambahBarisUnitEdit() {
    document.getElementById('editUnitRows').appendChild(buatBarisUnitEdit());
}

function hapusBarisUnitEdit(btn, unitId) {
    const rows = document.getElementById('editUnitRows');
    if (rows.children.length <= 1) {
        alert('Minimal harus ada 1 unit/objek yang diperiksa.');
        return;
    }
    if (unitId) {
        const hiddenHapus = document.getElementById('editUnitHapus');
        const existing = hiddenHapus.value ? hiddenHapus.value.split(',').filter(Boolean) : [];
        existing.push(String(unitId));
        hiddenHapus.value = existing.join(',');
    }
    btn.closest('.edit-unit-row').remove();
}

function openEditPengajuanModal(btn) {
    const data = JSON.parse(btn.getAttribute('data-edit'));

    document.getElementById('editPengajuanId').value = data.id;
    document.getElementById('editNamaPerusahaan').value = data.nama_perusahaan || '';
    document.getElementById('editDiajukanOleh').value = data.diajukan_oleh || '';
    document.getElementById('editTanggalDiinginkan').value = data.tanggal_diinginkan || '';
    document.getElementById('editUnitHapus').value = '';

    const rowsContainer = document.getElementById('editUnitRows');
    rowsContainer.innerHTML = '';
    const units = (data.units && data.units.length) ? data.units : [{ id: 0, nama: '', jenis: '' }];
    units.forEach(function (u) {
        rowsContainer.appendChild(buatBarisUnitEdit(u));
    });

    const modal = new bootstrap.Modal(document.getElementById('modalEditPengajuan'));
    modal.show();
}

function openApprovalModalSurat(suratId, decision, perihal) {
    document.getElementById('modalApprovalSuratId').value = suratId;
    document.getElementById('modalApprovalSuratDecision').value = decision;
    document.getElementById('modalApprovalSuratPerihal').textContent = perihal;

    const title = document.getElementById('modalApprovalSuratTitle');
    const submitBtn = document.getElementById('modalApprovalSuratSubmit');
    const catatanLabel = document.getElementById('modalApprovalSuratCatatanLabel');
    const catatanInput = document.getElementById('modalApprovalSuratCatatan');
    catatanInput.value = '';

    if (decision === 'approve') {
        title.textContent = 'Setujui Surat';
        submitBtn.textContent = 'Setujui';
        catatanLabel.textContent = 'Catatan (opsional)';
        catatanInput.required = false;
    } else {
        title.textContent = 'Tolak Surat';
        submitBtn.textContent = 'Tolak Surat';
        catatanLabel.textContent = 'Alasan Penolakan (wajib diisi)';
        catatanInput.required = true;
    }

    const modal = new bootstrap.Modal(document.getElementById('modalApprovalSurat'));
    modal.show();
}
</script>

<?php
include "../includes/footer.php";
?>