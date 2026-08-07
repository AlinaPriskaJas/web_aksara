<?php
// admin/suket.php
$page_title = "Surat Keterangan K3";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
require_once "../config/koneksi.php";

// Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$success_msg = "";
$error_msg = "";

// Handle Add/Edit Suket
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        $pengajuan_id = $_POST['pengajuan_id'] ?: null;
        $klien_id = $_POST['klien_id'];
        $objek_id = $_POST['objek_id'];
        $nomor_laporan = $_POST['nomor_laporan'];
        $jenis_pemeriksaan = $_POST['jenis_pemeriksaan'];
        $tanggal_jadwal = $_POST['tanggal_jadwal'] ?: null;
        $tanggal_pemeriksaan = $_POST['tanggal_pemeriksaan'] ?: null;
        $tanggal_expiry = $_POST['tanggal_expiry'] ?: null;
        $ahli_k3_id = $_POST['ahli_k3_id'];
        $verifikator_disnaker = $_POST['verifikator_disnaker'];
        $hasil_pemeriksaan = $_POST['hasil_pemeriksaan'] ?: null;
        $rekomendasi_teknis = $_POST['rekomendasi_teknis'];

        if (empty($klien_id) || empty($objek_id) || empty($nomor_laporan) || empty($ahli_k3_id)) {
            $error_msg = "Kolom Klien, Objek K3, Nomor Laporan, dan Ahli K3 wajib diisi!";
        } else {
            try {
                if (isset($_POST['id']) && !empty($_POST['id'])) {
                    // Update
                    $id = $_POST['id'];
                    $stmt = $conn->prepare("UPDATE Suket_K3 SET pengajuan_id = :pengajuan_id, klien_id = :klien_id, objek_id = :objek_id, nomor_laporan = :nomor_laporan, jenis_pemeriksaan = :jenis_pemeriksaan, tanggal_jadwal = :tanggal_jadwal, tanggal_pemeriksaan = :tanggal_pemeriksaan, tanggal_expiry = :tanggal_expiry, ahli_k3_id = :ahli_k3_id, verifikator_disnaker = :verifikator_disnaker, hasil_pemeriksaan = :hasil_pemeriksaan, rekomendasi_teknis = :rekomendasi_teknis WHERE id = :id");
                    $stmt->execute([
                        'pengajuan_id' => $pengajuan_id,
                        'klien_id' => $klien_id,
                        'objek_id' => $objek_id,
                        'nomor_laporan' => $nomor_laporan,
                        'jenis_pemeriksaan' => $jenis_pemeriksaan,
                        'tanggal_jadwal' => $tanggal_jadwal,
                        'tanggal_pemeriksaan' => $tanggal_pemeriksaan,
                        'tanggal_expiry' => $tanggal_expiry,
                        'ahli_k3_id' => $ahli_k3_id,
                        'verifikator_disnaker' => $verifikator_disnaker,
                        'hasil_pemeriksaan' => $hasil_pemeriksaan,
                        'rekomendasi_teknis' => $rekomendasi_teknis,
                        'id' => $id
                    ]);
                    $success_msg = "Suket K3 berhasil diperbarui!";
                } else {
                    // Insert
                    $stmt = $conn->prepare("INSERT INTO Suket_K3 (pengajuan_id, laporan_id, klien_id, objek_id, nomor_laporan, jenis_pemeriksaan, tanggal_jadwal, tanggal_pemeriksaan, tanggal_expiry, ahli_k3_id, verifikator_disnaker, hasil_pemeriksaan, rekomendasi_teknis) VALUES (:pengajuan_id, :laporan_id, :klien_id, :objek_id, :nomor_laporan, :jenis_pemeriksaan, :tanggal_jadwal, :tanggal_pemeriksaan, :tanggal_expiry, :ahli_k3_id, :verifikator_disnaker, :hasil_pemeriksaan, :rekomendasi_teknis)");
                    $stmt->execute([
                        'pengajuan_id' => $pengajuan_id,
                        'laporan_id' => $_POST['laporan_id'] ?: null,
                        'klien_id' => $klien_id,
                        'objek_id' => $objek_id,
                        'nomor_laporan' => $nomor_laporan, // ini nomor suket resmi, bisa beda dari nomor laporan asal
                        'jenis_pemeriksaan' => $jenis_pemeriksaan,
                        'tanggal_jadwal' => $tanggal_jadwal,
                        'tanggal_pemeriksaan' => $tanggal_pemeriksaan,
                        'tanggal_expiry' => $tanggal_expiry,
                        'ahli_k3_id' => $ahli_k3_id,
                        'verifikator_disnaker' => $verifikator_disnaker,
                        'hasil_pemeriksaan' => $hasil_pemeriksaan,
                        'rekomendasi_teknis' => $rekomendasi_teknis
                    ]);

                    $new_suket_id = $conn->lastInsertId();

                    // Tandai laporan sebagai sudah diterbitkan
                    if (!empty($_POST['laporan_id'])) {
                        $updLap = $conn->prepare("UPDATE Laporan_Pemeriksaan SET status = 'Sudah Diterbitkan', suket_id = :suket_id WHERE id = :laporan_id");
                        $updLap->execute(['suket_id' => $new_suket_id, 'laporan_id' => $_POST['laporan_id']]);
                    }

                    if ($pengajuan_id) {
                        $upd = $conn->prepare("UPDATE Pengajuan_Pemeriksaan SET status = 'Selesai', suket_id = :suket_id WHERE id = :pengajuan_id");
                        $upd->execute(['suket_id' => $new_suket_id, 'pengajuan_id' => $pengajuan_id]);
                    }

                    $success_msg = "Suket K3 berhasil diterbitkan!";
                }
            } catch (PDOException $e) {
                $error_msg = "Gagal memproses Suket: " . $e->getMessage();
            }
        }
    }
}

// Fetch Laporan_Pemeriksaan
$laporan_list = $conn->query("
    SELECT lp.*, dk.nama_perusahaan, o.nama_unit
    FROM Laporan_Pemeriksaan lp
    JOIN Data_Klien dk ON lp.klien_id = dk.id
    JOIN Objek_K3 o ON lp.objek_id = o.id
    WHERE lp.status != 'Sudah Diterbitkan'
    ORDER BY lp.created_at DESC
")->fetchAll();

// Fetch lists
$klien_list = $conn->query("SELECT id, nama_perusahaan FROM Data_Klien ORDER BY nama_perusahaan ASC")->fetchAll();

$objek_list = $conn->query("
    SELECT o.id, o.nama_unit, o.serial_number, dk.nama_perusahaan
    FROM Objek_K3 o
    JOIN Data_Klien dk ON o.id_client = dk.id
    ORDER BY dk.nama_perusahaan ASC
")->fetchAll();

$ahli_list = $conn->query("SELECT id, nama_lengkap, tingkat_ahli, bidang_keahlian FROM Sertifikat_Ahli ORDER BY nama_lengkap ASC")->fetchAll();

$pengajuan_list = $conn->query("SELECT id, jenis_pemeriksaan, klasifikasi_objek_k3 FROM Pengajuan_Pemeriksaan ORDER BY id DESC")->fetchAll();


// Fetch Suket listings
$sukets = $conn->query("
    SELECT s.*, dk.nama_perusahaan, o.nama_unit, sa.nama_lengkap AS nama_ahli 
    FROM Suket_K3 s
    JOIN Data_Klien dk ON s.klien_id = dk.id
    JOIN Objek_K3 o ON s.objek_id = o.id
    JOIN Sertifikat_Ahli sa ON s.ahli_k3_id = sa.id
    ORDER BY s.created_at DESC
")->fetchAll();
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
        <div class="col-12">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Penerbitan Surat Keterangan K3</h5>
                    <div class="table-toolbar-actions">
                        <div class="search-box-container">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari suket..."
                                data-table-search="tabelSuket" onkeyup="handleTableSearch('tabelSuket')">
                        </div>
                        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#suketModal"
                            onclick="resetForm()">
                            <i class="bi bi-plus-lg"></i> Terbitkan Suket Baru
                        </button>
                    </div>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom" id="tabelSuket">
                        <thead>
                            <tr>
                                <th>No Laporan / Suket</th>
                                <th>Nama Klien</th>
                                <th>Unit Objek</th>
                                <th>Ahli K3</th>
                                <th>Hasil Periksa</th>
                                <th>Tgl Expiry</th>
                                <th style="text-align: center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sukets) === 0): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-3 text-muted">Belum ada Suket K3 yang diterbitkan.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sukets as $s): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($s['nomor_laporan']) ?></strong></td>
                                        <td><?= htmlspecialchars($s['nama_perusahaan']) ?></td>
                                        <td><?= htmlspecialchars($s['nama_unit']) ?></td>
                                        <td><?= htmlspecialchars($s['nama_ahli']) ?></td>
                                        <td>
                                            <?php
                                            $badgeClass = "badge-success";
                                            if ($s['hasil_pemeriksaan'] === 'Tidak Layak')
                                                $badgeClass = "badge-danger";
                                            if ($s['hasil_pemeriksaan'] === 'Layak Bersyarat')
                                                $badgeClass = "badge-warning";
                                            ?>
                                            <span
                                                class="<?= $badgeClass ?>"><?= htmlspecialchars($s['hasil_pemeriksaan'] ?: 'Belum dinilai') ?></span>
                                        </td>
                                        <td><?= $s['tanggal_expiry'] ? date('d-m-Y', strtotime($s['tanggal_expiry'])) : '-' ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <button class="btn-primary-custom"
                                                style="height:32px; padding: 0 12px; font-size:0.8rem;" data-bs-toggle="modal"
                                                data-bs-target="#suketModal" onclick='editSuket(<?= json_encode($s) ?>)'>
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-custom" id="pagination-tabelSuket"></div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Form -->
<div class="modal fade modal-custom" id="suketModal" tabindex="-1" aria-labelledby="suketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="suket.php">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="form-id">

                <div class="modal-header">
                    <h5 class="modal-title" id="suketModalLabel">Penerbitan Suket K3</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Rujukan Pengajuan K3</label>
                            <select name="pengajuan_id" id="form-pengajuan-id" class="select-custom">
                                <option value="">-- Tanpa Rujukan Pengajuan --</option>
                                <?php foreach ($pengajuan_list as $p): ?>
                                    <option value="<?= $p['id'] ?>">ID: #<?= $p['id'] ?> -
                                        <?= htmlspecialchars($p['klasifikasi_objek_k3']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Pilih Laporan Pemeriksaan</label>
                            <select id="form-laporan-id" name="laporan_id" class="select-custom"
                                onchange="pilihLaporan(this.value)">
                                <option value="">-- Tanpa Rujukan Laporan --</option>
                                <?php foreach ($laporan_list as $lp): ?>
                                    <option value="<?= $lp['id'] ?>">
                                        <?= htmlspecialchars($lp['nomor_laporan']) ?> —
                                        <?= htmlspecialchars($lp['nama_perusahaan']) ?>
                                        (<?= htmlspecialchars($lp['nama_unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Nomor Laporan / Suket *</label>
                            <input type="text" name="nomor_laporan" id="form-nomor-laporan" class="form-control-custom"
                                placeholder="Contoh: SK3/ARP-01/2026" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Perusahaan Klien *</label>
                            <select name="klien_id" id="form-klien-id" class="select-custom" required>
                                <option value="">-- Pilih Klien --</option>
                                <?php foreach ($klien_list as $k): ?>
                                    <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_perusahaan']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Objek K3 Unit *</label>
                            <select name="objek_id" id="form-objek-id" class="select-custom" required>
                                <option value="">-- Pilih Objek Unit --</option>
                                <?php foreach ($objek_list as $o): ?>
                                    <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['nama_perusahaan']) ?> -
                                        <?= htmlspecialchars($o['nama_unit']) ?> (SN:
                                        <?= htmlspecialchars($o['serial_number'] ?: '-') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Ahli K3 Pelaksana *</label>
                            <select name="ahli_k3_id" id="form-ahli-id" class="select-custom" required>
                                <option value="">-- Pilih Ahli K3 --</option>
                                <?php foreach ($ahli_list as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['nama_lengkap']) ?> -
                                        <?= htmlspecialchars($a['tingkat_ahli']) ?>
                                        (<?= htmlspecialchars($a['bidang_keahlian']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Jenis Pemeriksaan</label>
                            <select name="jenis_pemeriksaan" id="form-jenis-pemeriksaan" class="select-custom">
                                <option value="Pemeriksaan Baru">Pemeriksaan Baru</option>
                                <option value="Pemeriksaan Berkala">Pemeriksaan Berkala</option>
                                <option value="Pemeriksaan Ulang">Pemeriksaan Ulang</option>
                                <option value="Pemeriksaan Khusus">Pemeriksaan Khusus</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7 mb-1">Tanggal Jadwal</label>
                            <input type="date" name="tanggal_jadwal" id="form-tgl-jadwal" class="form-control-custom">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7 mb-1">Tanggal Pemeriksaan</label>
                            <input type="date" name="tanggal_pemeriksaan" id="form-tgl-pemeriksaan"
                                class="form-control-custom">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold fs-7 mb-1">Tanggal Kedaluwarsa</label>
                            <input type="date" name="tanggal_expiry" id="form-tgl-expiry" class="form-control-custom">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Verifikator Disnaker</label>
                            <input type="text" name="verifikator_disnaker" id="form-verifikator"
                                class="form-control-custom" placeholder="Nama Verifikator Disnaker">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Hasil Kelayakan Pemeriksaan</label>
                            <select name="hasil_pemeriksaan" id="form-hasil" class="select-custom">
                                <option value="">-- Belum Dinilai --</option>
                                <option value="Layak">Layak</option>
                                <option value="Tidak Layak">Tidak Layak</option>
                                <option value="Layak Bersyarat">Layak Bersyarat</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-1">Rekomendasi Teknis K3</label>
                            <textarea name="rekomendasi_teknis" id="form-rekomendasi" class="textarea-custom"
                                placeholder="Tuliskan butir rekomendasi K3..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-custom">Simpan Suket</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelSuket', 10);
    });

    function resetForm() {
        document.getElementById('form-pilih-laporan').value = '';
        document.getElementById('form-id').value = '';
        document.getElementById('form-pengajuan-id').value = '';
        document.getElementById('form-nomor-laporan').value = '';
        document.getElementById('form-klien-id').value = '';
        document.getElementById('form-objek-id').value = '';
        document.getElementById('form-ahli-id').value = '';
        document.getElementById('form-jenis-pemeriksaan').value = 'Pemeriksaan Baru';
        document.getElementById('form-tgl-jadwal').value = '';
        document.getElementById('form-tgl-pemeriksaan').value = '';
        document.getElementById('form-tgl-expiry').value = '';
        document.getElementById('form-verifikator').value = '';
        document.getElementById('form-hasil').value = '';
        document.getElementById('form-rekomendasi').value = '';
        document.getElementById('suketModalLabel').textContent = 'Terbitkan Suket Baru';
    }

    function editSuket(data) {
        document.getElementById('form-id').value = data.id;
        document.getElementById('form-pengajuan-id').value = data.pengajuan_id || '';
        document.getElementById('form-nomor-laporan').value = data.nomor_laporan;
        document.getElementById('form-klien-id').value = data.klien_id;
        document.getElementById('form-objek-id').value = data.objek_id;
        document.getElementById('form-ahli-id').value = data.ahli_k3_id;
        document.getElementById('form-jenis-pemeriksaan').value = data.jenis_pemeriksaan;
        document.getElementById('form-tgl-jadwal').value = data.tanggal_jadwal || '';
        document.getElementById('form-tgl-pemeriksaan').value = data.tanggal_pemeriksaan || '';
        document.getElementById('form-tgl-expiry').value = data.tanggal_expiry || '';
        document.getElementById('form-verifikator').value = data.verifikator_disnaker || '';
        document.getElementById('form-hasil').value = data.hasil_pemeriksaan || '';
        document.getElementById('form-rekomendasi').value = data.rekomendasi_teknis || '';
        document.getElementById('suketModalLabel').textContent = 'Edit Suket K3';
    }

    const laporanDataAll = <?= json_encode($laporan_list) ?>;

    function pilihLaporan(id) {
        if (!id) return;
        const lp = laporanDataAll.find(l => String(l.id) === String(id));
        if (!lp) return;
        document.getElementById('form-klien-id').value = lp.klien_id;
        document.getElementById('form-objek-id').value = lp.objek_id;
        document.getElementById('form-ahli-id').value = lp.ahli_k3_id;
        document.getElementById('form-jenis-pemeriksaan').value = lp.jenis_pemeriksaan;
        document.getElementById('form-tgl-pemeriksaan').value = lp.tanggal_pemeriksaan || '';
        document.getElementById('form-tgl-expiry').value = lp.tanggal_expiry || '';
        document.getElementById('form-hasil').value = lp.hasil_pemeriksaan || '';
        document.getElementById('form-rekomendasi').value = lp.rekomendasi_teknis || '';
        // Nomor laporan suket dikosongkan / diketik admin sendiri sebagai nomor resmi baru
    }
</script>

<?php
include "../includes/footer.php";
?>