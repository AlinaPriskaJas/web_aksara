<?php
// admin/jadwal.php
$page_title = "Jadwal Pemeriksaan K3";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
require_once "../config/koneksi.php";

// Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];
$success_msg = "";
$error_msg = "";

// Handle Add/Edit Jadwal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        $pengajuan_id = $_POST['pengajuan_id'];
        $ahli_k3_id = $_POST['ahli_k3_id'];
        $klien_id = $_POST['klien_id'];
        $tanggal = $_POST['tanggal'];
        $jam_mulai = $_POST['jam_mulai'];
        $jam_selesai = $_POST['jam_selesai'] ?: null;
        $lokasi = $_POST['lokasi'];
        $catatan = $_POST['catatan'];
        $status = $_POST['status'] ?? 'Terjadwal';

        if (empty($pengajuan_id) || empty($ahli_k3_id) || empty($klien_id) || empty($tanggal) || empty($jam_mulai)) {
            $error_msg = "Semua kolom bertanda * wajib diisi!";
        } else {
            try {
                if (isset($_POST['id']) && !empty($_POST['id'])) {
                    // Edit / Update
                    $id = $_POST['id'];
                    // Log reschedule if date or time changed
                    $checkStmt = $conn->prepare("SELECT tanggal, jam_mulai FROM Jadwal_Pemeriksaan WHERE id = :id");
                    $checkStmt->execute(['id' => $id]);
                    $old = $checkStmt->fetch();

                    if ($old && ($old['tanggal'] !== $tanggal || $old['jam_mulai'] !== $jam_mulai)) {
                        $logStmt = $conn->prepare("INSERT INTO Jadwal_Reschedule_Log (jadwal_id, tanggal_lama, jam_lama, tanggal_baru, jam_baru, alasan, diubah_oleh) VALUES (:jadwal_id, :tgl_lama, :jam_lama, :tgl_baru, :jam_baru, :alasan, :user_id)");
                        $logStmt->execute([
                            'jadwal_id' => $id,
                            'tgl_lama' => $old['tanggal'],
                            'jam_lama' => $old['jam_mulai'],
                            'tgl_baru' => $tanggal,
                            'jam_baru' => $jam_mulai,
                            'alasan' => 'Penyesuaian jadwal operasional oleh Admin',
                            'user_id' => $current_user_id
                        ]);
                    }

                    $stmt = $conn->prepare("UPDATE Jadwal_Pemeriksaan SET pengajuan_id = :pengajuan_id, ahli_k3_id = :ahli_k3_id, klien_id = :klien_id, tanggal = :tanggal, jam_mulai = :jam_mulai, jam_selesai = :jam_selesai, lokasi = :lokasi, status = :status, catatan = :catatan WHERE id = :id");
                    $stmt->execute([
                        'pengajuan_id' => $pengajuan_id,
                        'ahli_k3_id' => $ahli_k3_id,
                        'klien_id' => $klien_id,
                        'tanggal' => $tanggal,
                        'jam_mulai' => $jam_mulai,
                        'jam_selesai' => $jam_selesai,
                        'lokasi' => $lokasi,
                        'status' => $status,
                        'catatan' => $catatan,
                        'id' => $id
                    ]);
                    $success_msg = "Jadwal pemeriksaan berhasil diperbarui!";
                } else {
                    // Create New
                    $stmt = $conn->prepare("INSERT INTO Jadwal_Pemeriksaan (pengajuan_id, ahli_k3_id, klien_id, tanggal, jam_mulai, jam_selesai, lokasi, status, catatan, dijadwalkan_oleh) VALUES (:pengajuan_id, :ahli_k3_id, :klien_id, :tanggal, :jam_mulai, :jam_selesai, :lokasi, :status, :catatan, :user_id)");
                    $stmt->execute([
                        'pengajuan_id' => $pengajuan_id,
                        'ahli_k3_id' => $ahli_k3_id,
                        'klien_id' => $klien_id,
                        'tanggal' => $tanggal,
                        'jam_mulai' => $jam_mulai,
                        'jam_selesai' => $jam_selesai,
                        'lokasi' => $lokasi,
                        'status' => $status,
                        'catatan' => $catatan,
                        'user_id' => $current_user_id
                    ]);

                    // Update Status Pengajuan_Pemeriksaan to 'Dijadwalkan'
                    $updPengajuan = $conn->prepare("UPDATE Pengajuan_Pemeriksaan SET status = 'Dijadwalkan' WHERE id = :pengajuan_id");
                    $updPengajuan->execute(['pengajuan_id' => $pengajuan_id]);

                    $success_msg = "Jadwal pemeriksaan baru berhasil ditambahkan!";
                }
            } catch (PDOException $e) {
                $error_msg = "Gagal menyimpan data: " . $e->getMessage();
            }
        }
    }
}

// Get Lists for Forms
$klien_list = $conn->query("SELECT id, nama_perusahaan FROM Data_Klien ORDER BY nama_perusahaan ASC")->fetchAll();
$ahli_list = $conn->query("SELECT id, nama_lengkap, tingkat_ahli, bidang_keahlian FROM Sertifikat_Ahli ORDER BY nama_lengkap ASC")->fetchAll();
$pengajuan_list = $conn->query("SELECT id, klasifikasi_objek_k3, jenis_pemeriksaan FROM Pengajuan_Pemeriksaan WHERE status IN ('Menunggu Verifikasi', 'Diverifikasi') ORDER BY id DESC")->fetchAll();
 
// Get Jadwal Data
$stmtJadwal = $conn->query("
    SELECT jp.*, dk.nama_perusahaan, sa.nama_lengkap AS nama_ahli, sa.tingkat_ahli, sa.bidang_keahlian, u.nama_lengkap AS nama_admin 
    FROM Jadwal_Pemeriksaan jp
    JOIN Data_Klien dk ON jp.klien_id = dk.id
    JOIN Sertifikat_Ahli sa ON jp.ahli_k3_id = sa.id
    JOIN Users u ON jp.dijadwalkan_oleh = u.id
    ORDER BY jp.tanggal DESC, jp.jam_mulai DESC
");
$jadwals = $stmtJadwal->fetchAll();
?>

<main class="main-content">
    <?php if ($success_msg): ?>
        <div class="alert alert-success-custom align-items-center">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div><?= htmlspecialchars($success_msg) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger-custom align-items-center">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?= htmlspecialchars($error_msg) ?></div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- List Table -->
        <div class="col-12">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Jadwal Survey &amp; Pemeriksaan K3</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari jadwal..."
                                data-table-search="tabelJadwal" onkeyup="handleTableSearch('tabelJadwal')">
                        </div>
                        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#jadwalModal"
                            onclick="resetForm()">
                            <i class="bi bi-plus-lg"></i> Buat Jadwal Baru
                        </button>
                    </div>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelJadwal">
                        <thead>
                            <tr>
                                <th>Tanggal &amp; Waktu</th>
                                <th>Nama Klien</th>
                                <th>Ahli K3 &amp; SKP</th>
                                <th>Lokasi</th>
                                <th>Status</th>
                                <th>Dijadwalkan Oleh</th>
                                <th style="text-align: center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($jadwals) === 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">Belum ada jadwal survey atau
                                        pemeriksaan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($jadwals as $j): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= date('d M Y', strtotime($j['tanggal'])) ?></div>
                                            <small class="text-secondary"><?= substr($j['jam_mulai'], 0, 5) ?> -
                                                <?= $j['jam_selesai'] ? substr($j['jam_selesai'], 0, 5) : 'Selesai' ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($j['nama_perusahaan']) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($j['nama_ahli']) ?></div>
                                            <span class="badge bg-secondary" style="font-size:0.75rem;">SKP:
                                                <?= htmlspecialchars($j['skp']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($j['lokasi'] ?: '-') ?></td>
                                        <td>
                                            <?php
                                            $badgeClass = 'badge-warning';
                                            if ($j['status'] === 'Selesai')
                                                $badgeClass = 'badge-success';
                                            if ($j['status'] === 'Dibatalkan')
                                                $badgeClass = 'badge-danger';
                                            ?>
                                            <span class="<?= $badgeClass ?>"><?= htmlspecialchars($j['status']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($j['nama_admin']) ?></td>
                                        <td style="text-align: center;">
                                            <button class="btn-primary-custom"
                                                style="height:32px; padding: 0 12px; font-size:0.8rem;" data-bs-toggle="modal"
                                                data-bs-target="#jadwalModal" onclick='editJadwal(<?= json_encode($j) ?>)'>
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelJadwal"></div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Form -->
<div class="modal fade modal-custom" id="jadwalModal" tabindex="-1" aria-labelledby="jadwalModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="jadwal.php">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="form-id">

                <div class="modal-header">
                    <h5 class="modal-title" id="jadwalModalLabel">Kelola Jadwal Pemeriksaan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Pilih Pengajuan K3 <span
                                    class="text-danger">*</span></label>
                            <select name="pengajuan_id" id="form-pengajuan-id" class="select-custom" required>
                                <option value="">-- Pilih Rujukan Pengajuan --</option>
                                <?php foreach ($pengajuan_list as $p): ?>
                                    <option value="<?= $p['id'] ?>">ID: #<?= $p['id'] ?> -
                                        <?= htmlspecialchars($p['klasifikasi_objek_k3']) ?>
                                        (<?= htmlspecialchars($p['jenis_pemeriksaan']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Perusahaan Klien <span
                                    class="text-danger">*</span></label>
                            <select name="klien_id" id="form-klien-id" class="select-custom" required>
                                <option value="">-- Pilih Klien --</option>
                                <?php foreach ($klien_list as $k): ?>
                                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_perusahaan']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Ahli K3 Pelaksana <span
                                    class="text-danger">*</span></label>
                            <select name="ahli_k3_id" id="form-ahli-id" class="select-custom" required>
                                <option value="">-- Pilih Ahli K3 --</option>
                                <?php foreach ($ahli_list as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['nama_lengkap']) ?> - SKP:
                                        <?= htmlspecialchars($a['skp']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Tanggal Pemeriksaan <span
                                    class="text-danger">*</span></label>
                            <input type="date" name="tanggal" id="form-tanggal" class="form-control-custom" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold fs-7 mb-1">Jam Mulai <span
                                    class="text-danger">*</span></label>
                            <input type="time" name="jam_mulai" id="form-jam-mulai" class="form-control-custom"
                                required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold fs-7 mb-1">Jam Selesai</label>
                            <input type="time" name="jam_selesai" id="form-jam-selesai" class="form-control-custom">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Status</label>
                            <select name="status" id="form-status" class="select-custom">
                                <option value="Terjadwal">Terjadwal</option>
                                <option value="Reschedule">Reschedule</option>
                                <option value="Berlangsung">Berlangsung</option>
                                <option value="Selesai">Selesai</option>
                                <option value="Dibatalkan">Dibatalkan</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-1">Lokasi Pemeriksaan/Survey</label>
                            <input type="text" name="lokasi" id="form-lokasi" class="form-control-custom"
                                placeholder="Nama Gedung, Lantai, atau Detail Alamat">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-1">Catatan Tambahan</label>
                            <textarea name="catatan" id="form-catatan" class="textarea-custom"
                                placeholder="Tuliskan instruksi atau memo khusus..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan Jadwal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelJadwal', 10);
    });

    function resetForm() {
        document.getElementById('form-id').value = '';
        document.getElementById('form-pengajuan-id').value = '';
        document.getElementById('form-klien-id').value = '';
        document.getElementById('form-ahli-id').value = '';
        document.getElementById('form-tanggal').value = '';
        document.getElementById('form-jam-mulai').value = '';
        document.getElementById('form-jam-selesai').value = '';
        document.getElementById('form-status').value = 'Terjadwal';
        document.getElementById('form-lokasi').value = '';
        document.getElementById('form-catatan').value = '';
        document.getElementById('jadwalModalLabel').textContent = 'Buat Jadwal Baru';
    }

    function editJadwal(data) {
        document.getElementById('form-id').value = data.id;

        // Add option to select if not exist (since it might be already dijadwalkan)
        const optPengajuan = document.getElementById('form-pengajuan-id');
        let exist = false;
        for (let i = 0; i < optPengajuan.options.length; i++) {
            if (optPengajuan.options[i].value == data.pengajuan_id) {
                exist = true;
                break;
            }
        }
        if (!exist) {
            const option = document.createElement("option");
            option.text = "ID: #"data.pengajuan_id" (Jadwal Terdaftar)";
            option.value = data.pengajuan_id;
            optPengajuan.add(option);
        }

        document.getElementById('form-pengajuan-id').value = data.pengajuan_id;
        document.getElementById('form-klien-id').value = data.klien_id;
        document.getElementById('form-ahli-id').value = data.ahli_k3_id;
        document.getElementById('form-tanggal').value = data.tanggal;
        document.getElementById('form-jam-mulai').value = data.jam_mulai;
        document.getElementById('form-jam-selesai').value = data.jam_selesai || '';
        document.getElementById('form-status').value = data.status;
        document.getElementById('form-lokasi').value = data.lokasi || '';
        document.getElementById('form-catatan').value = data.catatan || '';
        document.getElementById('jadwalModalLabel').textContent = 'Edit Jadwal Pemeriksaan';
    }
</script>

<?php
include "../includes/footer.php";
?>