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
            <form method="POST" action="suket.php" id="suketForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="form-id">

                <div class="modal-header">
                    <h5 class="modal-title" id="suketModalLabel">Penerbitan Suket K3</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Rujukan Pengajuan K3 (autocomplete, opsional) -->
                        <div class="col-md-6 autocomplete-wrapper">
                            <label class="form-label fw-semibold fs-7 mb-1">Rujukan Pengajuan K3</label>
                            <input type="text" id="form-pengajuan-search" class="form-control-custom"
                                placeholder="Ketik ID / jenis pemeriksaan / klasifikasi objek..." autocomplete="off">
                            <input type="hidden" name="pengajuan_id" id="form-pengajuan-id">
                            <div class="autocomplete-list" id="form-pengajuan-list"></div>
                        </div>

                        <!-- Pilih Laporan Pemeriksaan (autocomplete) -->
                        <div class="col-6 autocomplete-wrapper">
                            <label class="form-label fw-semibold fs-7 mb-1">Pilih Laporan Pemeriksaan</label>
                            <input type="text" id="form-laporan-search" class="form-control-custom"
                                placeholder="Ketik nomor laporan / nama klien..." autocomplete="off">
                            <input type="hidden" name="laporan_id" id="form-laporan-id">
                            <div class="autocomplete-list" id="form-laporan-list"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold fs-7 mb-1">Nomor Laporan / Suket *</label>
                            <input type="text" name="nomor_laporan" id="form-nomor-laporan" class="form-control-custom"
                                placeholder="Contoh: SK3/ARP-01/2026" required>
                        </div>

                        <!-- Perusahaan Klien (autocomplete) -->
                        <div class="col-md-6 autocomplete-wrapper">
                            <label class="form-label fw-semibold fs-7 mb-1">Perusahaan Klien *</label>
                            <input type="text" id="form-klien-search" class="form-control-custom"
                                placeholder="Ketik nama perusahaan..." autocomplete="off">
                            <input type="hidden" name="klien_id" id="form-klien-id">
                            <div class="autocomplete-list" id="form-klien-list"></div>
                        </div>

                        <!-- Objek K3 Unit (autocomplete) -->
                        <div class="col-md-6 autocomplete-wrapper">
                            <label class="form-label fw-semibold fs-7 mb-1">Objek K3 Unit *</label>
                            <input type="text" id="form-objek-search" class="form-control-custom"
                                placeholder="Ketik nama unit / SN / klien..." autocomplete="off">
                            <input type="hidden" name="objek_id" id="form-objek-id">
                            <div class="autocomplete-list" id="form-objek-list"></div>
                        </div>

                        <!-- Ahli K3 Pelaksana (autocomplete) -->
                        <div class="col-md-6 autocomplete-wrapper">
                            <label class="form-label fw-semibold fs-7 mb-1">Ahli K3 Pelaksana *</label>
                            <input type="text" id="form-ahli-search" class="form-control-custom"
                                placeholder="Ketik nama ahli K3..." autocomplete="off">
                            <input type="hidden" name="ahli_k3_id" id="form-ahli-id">
                            <div class="autocomplete-list" id="form-ahli-list"></div>
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

<style>
    .autocomplete-wrapper {
        position: relative;
    }

    .autocomplete-list {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 2000;
        max-height: 220px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #d9dde3;
        border-radius: 8px;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        margin-top: 4px;
    }

    .autocomplete-list.show {
        display: block;
    }

    .autocomplete-item {
        padding: 8px 12px;
        font-size: 0.85rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f2f4;
    }

    .autocomplete-item:last-child {
        border-bottom: none;
    }

    .autocomplete-item:hover,
    .autocomplete-item.active {
        background: #f0f4ff;
    }

    .autocomplete-item small {
        display: block;
        color: #8a8f98;
        font-size: 0.75rem;
    }

    .autocomplete-empty {
        padding: 8px 12px;
        font-size: 0.8rem;
        color: #9aa0a8;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelSuket', 10);
    });

    // ===== Data untuk autocomplete =====
    const laporanDataAll = <?= json_encode($laporan_list) ?>;
    const klienDataAll = <?= json_encode($klien_list) ?>;
    const objekDataAll = <?= json_encode($objek_list) ?>;
    const ahliDataAll = <?= json_encode($ahli_list) ?>;
    const pengajuanDataAll = <?= json_encode($pengajuan_list) ?>;

    /**
     * Membuat komponen autocomplete generik.
     * @param {string} searchId  id input teks (yang diketik user)
     * @param {string} hiddenId  id input hidden (id sebenarnya yang dikirim ke server)
     * @param {string} listId    id container dropdown hasil pencarian
     * @param {Array}  data      array data sumber
     * @param {Function} matchFn (item, query) => boolean, menentukan apakah item cocok dengan query
     * @param {Function} labelFn (item) => string, label utama yang ditampilkan & dipakai mengisi input saat dipilih
     * @param {Function} subLabelFn (item) => string, label kecil tambahan (opsional)
     * @param {Function} onSelect (item) => void, dipanggil saat item dipilih
     */
    function setupAutocomplete({ searchId, hiddenId, listId, data, matchFn, labelFn, subLabelFn, onSelect }) {
        const searchEl = document.getElementById(searchId);
        const hiddenEl = document.getElementById(hiddenId);
        const listEl = document.getElementById(listId);

        function renderList(query) {
            const q = (query || '').trim().toLowerCase();
            const filtered = data.filter(item => matchFn(item, q));

            listEl.innerHTML = '';

            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'autocomplete-empty';
                empty.textContent = 'Tidak ditemukan';
                listEl.appendChild(empty);
            } else {
                filtered.slice(0, 50).forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'autocomplete-item';
                    const sub = subLabelFn ? subLabelFn(item) : '';
                    row.innerHTML = labelFn(item) + (sub ? `<small>${sub}</small>` : '');
                    row.addEventListener('mousedown', function (e) {
                        // mousedown supaya tereksekusi sebelum event blur pada input
                        e.preventDefault();
                        searchEl.value = labelFn(item).replace(/<[^>]*>/g, '');
                        hiddenEl.value = item.id;
                        listEl.classList.remove('show');
                        if (onSelect) onSelect(item);
                    });
                    listEl.appendChild(row);
                });
            }

            listEl.classList.add('show');
        }

        searchEl.addEventListener('focus', function () {
            renderList(searchEl.value);
        });

        searchEl.addEventListener('input', function () {
            hiddenEl.value = ''; // reset pilihan sampai user memilih ulang dari daftar
            renderList(searchEl.value);
        });

        searchEl.addEventListener('blur', function () {
            setTimeout(() => listEl.classList.remove('show'), 150);
        });

        // API kecil untuk set nilai secara programatik (dipakai saat edit / pilih laporan)
        searchEl._setValue = function (text, id) {
            searchEl.value = text || '';
            hiddenEl.value = id || '';
        };
    }

    // --- Rujukan Pengajuan K3 (opsional) ---
    setupAutocomplete({
        searchId: 'form-pengajuan-search',
        hiddenId: 'form-pengajuan-id',
        listId: 'form-pengajuan-list',
        data: pengajuanDataAll,
        matchFn: (item, q) => !q ||
            String(item.id).includes(q) ||
            (item.jenis_pemeriksaan || '').toLowerCase().includes(q) ||
            (item.klasifikasi_objek_k3 || '').toLowerCase().includes(q),
        labelFn: (item) => `ID: #${item.id} - ${item.klasifikasi_objek_k3 || '-'}`,
        subLabelFn: (item) => item.jenis_pemeriksaan || ''
    });

    // --- Klien ---
    setupAutocomplete({
        searchId: 'form-klien-search',
        hiddenId: 'form-klien-id',
        listId: 'form-klien-list',
        data: klienDataAll,
        matchFn: (item, q) => !q || item.nama_perusahaan.toLowerCase().includes(q),
        labelFn: (item) => item.nama_perusahaan
    });

    // --- Objek K3 ---
    setupAutocomplete({
        searchId: 'form-objek-search',
        hiddenId: 'form-objek-id',
        listId: 'form-objek-list',
        data: objekDataAll,
        matchFn: (item, q) => !q ||
            item.nama_unit.toLowerCase().includes(q) ||
            item.nama_perusahaan.toLowerCase().includes(q) ||
            (item.serial_number || '').toLowerCase().includes(q),
        labelFn: (item) => `${item.nama_perusahaan} - ${item.nama_unit}`,
        subLabelFn: (item) => `SN: ${item.serial_number || '-'}`
    });

    // --- Ahli K3 ---
    setupAutocomplete({
        searchId: 'form-ahli-search',
        hiddenId: 'form-ahli-id',
        listId: 'form-ahli-list',
        data: ahliDataAll,
        matchFn: (item, q) => !q ||
            item.nama_lengkap.toLowerCase().includes(q) ||
            (item.bidang_keahlian || '').toLowerCase().includes(q),
        labelFn: (item) => item.nama_lengkap,
        subLabelFn: (item) => `${item.tingkat_ahli} - ${item.bidang_keahlian}`
    });

    // --- Laporan Pemeriksaan (memicu auto-isi field lain saat dipilih) ---
    setupAutocomplete({
        searchId: 'form-laporan-search',
        hiddenId: 'form-laporan-id',
        listId: 'form-laporan-list',
        data: laporanDataAll,
        matchFn: (item, q) => !q ||
            item.nomor_laporan.toLowerCase().includes(q) ||
            item.nama_perusahaan.toLowerCase().includes(q) ||
            item.nama_unit.toLowerCase().includes(q),
        labelFn: (item) => item.nomor_laporan,
        subLabelFn: (item) => `${item.nama_perusahaan} (${item.nama_unit})`,
        onSelect: (lp) => pilihLaporan(lp)
    });

    function resetForm() {
        document.getElementById('form-id').value = '';
        document.getElementById('form-pengajuan-search').value = '';
        document.getElementById('form-pengajuan-id').value = '';

        document.getElementById('form-laporan-search').value = '';
        document.getElementById('form-laporan-id').value = '';

        document.getElementById('form-nomor-laporan').value = '';

        document.getElementById('form-klien-search').value = '';
        document.getElementById('form-klien-id').value = '';

        document.getElementById('form-objek-search').value = '';
        document.getElementById('form-objek-id').value = '';

        document.getElementById('form-ahli-search').value = '';
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

        if (data.pengajuan_id) {
            const pengajuan = pengajuanDataAll.find(p => String(p.id) === String(data.pengajuan_id));
            document.getElementById('form-pengajuan-search').value = pengajuan
                ? `ID: #${pengajuan.id} - ${pengajuan.klasifikasi_objek_k3 || '-'}`
                : `ID: #${data.pengajuan_id}`;
            document.getElementById('form-pengajuan-id').value = data.pengajuan_id;
        } else {
            document.getElementById('form-pengajuan-search').value = '';
            document.getElementById('form-pengajuan-id').value = '';
        }

        // Laporan tidak diisi otomatis saat edit (suket sudah terbit, biasanya tanpa rujukan baru)
        document.getElementById('form-laporan-search').value = '';
        document.getElementById('form-laporan-id').value = '';

        document.getElementById('form-nomor-laporan').value = data.nomor_laporan;

        const klien = klienDataAll.find(k => String(k.id) === String(data.klien_id));
        document.getElementById('form-klien-search').value = klien ? klien.nama_perusahaan : (data.nama_perusahaan || '');
        document.getElementById('form-klien-id').value = data.klien_id;

        const objek = objekDataAll.find(o => String(o.id) === String(data.objek_id));
        document.getElementById('form-objek-search').value = objek ? `${objek.nama_perusahaan} - ${objek.nama_unit}` : (data.nama_unit || '');
        document.getElementById('form-objek-id').value = data.objek_id;

        const ahli = ahliDataAll.find(a => String(a.id) === String(data.ahli_k3_id));
        document.getElementById('form-ahli-search').value = ahli ? ahli.nama_lengkap : (data.nama_ahli || '');
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

    function pilihLaporan(lp) {
        if (!lp) return;

        const klien = klienDataAll.find(k => String(k.id) === String(lp.klien_id));
        document.getElementById('form-klien-search').value = klien ? klien.nama_perusahaan : (lp.nama_perusahaan || '');
        document.getElementById('form-klien-id').value = lp.klien_id;

        const objek = objekDataAll.find(o => String(o.id) === String(lp.objek_id));
        document.getElementById('form-objek-search').value = objek ? `${objek.nama_perusahaan} - ${objek.nama_unit}` : (lp.nama_unit || '');
        document.getElementById('form-objek-id').value = lp.objek_id;

        const ahli = ahliDataAll.find(a => String(a.id) === String(lp.ahli_k3_id));
        document.getElementById('form-ahli-search').value = ahli ? ahli.nama_lengkap : '';
        document.getElementById('form-ahli-id').value = lp.ahli_k3_id;

        document.getElementById('form-jenis-pemeriksaan').value = lp.jenis_pemeriksaan;
        document.getElementById('form-tgl-pemeriksaan').value = lp.tanggal_pemeriksaan || '';
        document.getElementById('form-tgl-expiry').value = lp.tanggal_expiry || '';
        document.getElementById('form-hasil').value = lp.hasil_pemeriksaan || '';
        document.getElementById('form-rekomendasi').value = lp.rekomendasi_teknis || '';
        // Nomor laporan suket dikosongkan / diketik admin sendiri sebagai nomor resmi baru
    }

    // Validasi manual sebelum submit, karena field wajib sekarang berupa hidden input
    document.getElementById('suketForm').addEventListener('submit', function (e) {
        const requiredHidden = [
            { id: 'form-klien-id', label: 'Perusahaan Klien' },
            { id: 'form-objek-id', label: 'Objek K3 Unit' },
            { id: 'form-ahli-id', label: 'Ahli K3 Pelaksana' }
        ];
        for (const f of requiredHidden) {
            if (!document.getElementById(f.id).value) {
                e.preventDefault();
                alert(`Silakan pilih "${f.label}" dari daftar hasil pencarian sebelum menyimpan.`);
                return;
            }
        }
    });
</script>

<?php
include "../includes/footer.php";
?>