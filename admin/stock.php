<?php
// admin/stock.php
$page_title = "Laporan Stock Opname";
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
$current_user_id = $_SESSION['user_id'];
$active_tab = 'tabPanelStok';

// Handle Inventory Mutations / Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle Tambah Barang Baru (New Item)
    if (isset($_POST['action']) && $_POST['action'] === 'tambah_barang') {
        $kode_barang = trim($_POST['kode_barang']);
        $nama_barang = trim($_POST['nama_barang']);
        $kategori_pilih = trim($_POST['kategori_pilih']);
        $kategori_manual = trim($_POST['kategori_manual'] ?? '');
        $satuan = trim($_POST['satuan']);
        $stok_awal = intval($_POST['stok_awal']);
        $stok_minimum = ($_POST['stok_minimum'] !== '') ? intval($_POST['stok_minimum']) : null;
        $lokasi_rak = trim($_POST['lokasi_rak']);

        // Nama kategori final: kalau pilih "Lainnya" pakai input manual
        $nama_kategori = ($kategori_pilih === 'Lainnya') ? $kategori_manual : $kategori_pilih;

        if (empty($kode_barang) || empty($nama_barang) || empty($nama_kategori) || empty($satuan)) {
            $error_msg = "Semua field wajib (Kode, Nama Barang, Kategori, Satuan) harus diisi!";
        } else {
            try {
                $conn->beginTransaction();

                // 1. Cari atau buat kategori_barang_gudang
                $stmtCekKat = $conn->prepare("SELECT id_kategori FROM kategori_barang_gudang WHERE nama_kategori = :nama");
                $stmtCekKat->execute(['nama' => $nama_kategori]);
                $kat = $stmtCekKat->fetch();

                if ($kat) {
                    $id_kategori = $kat['id_kategori'];
                } else {
                    $stmtInsKat = $conn->prepare("INSERT INTO kategori_barang_gudang (nama_kategori) VALUES (:nama)");
                    $stmtInsKat->execute(['nama' => $nama_kategori]);
                    $id_kategori = $conn->lastInsertId();
                }

                // 2. Cari atau buat jenis_barang_gudang di bawah kategori tsb
                $stmtCekJenis = $conn->prepare("SELECT id_jenis FROM jenis_barang_gudang WHERE id_kategori = :id_kategori AND nama_jenis = :nama");
                $stmtCekJenis->execute(['id_kategori' => $id_kategori, 'nama' => $nama_kategori]);
                $jenis = $stmtCekJenis->fetch();

                if ($jenis) {
                    $id_jenis = $jenis['id_jenis'];
                } else {
                    $stmtInsJenis = $conn->prepare("INSERT INTO jenis_barang_gudang (id_kategori, nama_jenis) VALUES (:id_kategori, :nama)");
                    $stmtInsJenis->execute(['id_kategori' => $id_kategori, 'nama' => $nama_kategori]);
                    $id_jenis = $conn->lastInsertId();
                }

                // 3. Cek kode barang belum dipakai
                $stmtCekKode = $conn->prepare("SELECT id FROM Gudang_Stok WHERE kode_barang = :kode");
                $stmtCekKode->execute(['kode' => $kode_barang]);
                if ($stmtCekKode->fetch()) {
                    throw new Exception("Kode Barang/SKU '$kode_barang' sudah digunakan, gunakan kode lain.");
                }

                // 4. Insert barang baru ke Gudang_Stok
                $stmtInsBarang = $conn->prepare("INSERT INTO Gudang_Stok (kode_barang, nama_barang, id_jenis, satuan, stok_sistem, lokasi_rak, stok_minimum)
                    VALUES (:kode, :nama, :id_jenis, :satuan, :stok, :rak, :min)");
                $stmtInsBarang->execute([
                    'kode' => $kode_barang,
                    'nama' => $nama_barang,
                    'id_jenis' => $id_jenis,
                    'satuan' => $satuan,
                    'stok' => $stok_awal,
                    'rak' => $lokasi_rak,
                    'min' => $stok_minimum
                ]);

                $barang_baru_id = $conn->lastInsertId();

                // 5. Catat sebagai mutasi awal (opname) supaya tercatat di riwayat mutasi jika stok awal > 0
                if ($stok_awal > 0) {
                    $stmtMutAwal = $conn->prepare("INSERT INTO Mutasi_Stok (barang_id, jenis_mutasi, jumlah, tanggal, keterangan, dibuat_oleh) VALUES (:barang_id, 'Penyesuaian Opname', :jumlah, :tanggal, 'Stok awal saat penambahan barang baru', :user_id)");
                    $stmtMutAwal->execute([
                        'barang_id' => $barang_baru_id,
                        'jumlah' => $stok_awal,
                        'tanggal' => date('Y-m-d'),
                        'user_id' => $current_user_id
                    ]);
                }

                $conn->commit();
                $success_msg = "Barang baru '$nama_barang' berhasil ditambahkan ke gudang!";
            } catch (Exception $e) {
                $conn->rollBack();
                $error_msg = "Gagal menambah barang: " . $e->getMessage();
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'mutate') {
        $active_tab = 'tabPanelMutasi';
        $barang_id = $_POST['barang_id'];
        $jenis_mutasi = $_POST['jenis_mutasi'];
        $jumlah = intval($_POST['jumlah']);
        $keterangan = $_POST['keterangan'];
        $tanggal = date('Y-m-d');

        if (empty($barang_id) || $jumlah <= 0) {
            $error_msg = "Pilih barang dan jumlah mutasi yang valid!";
        } else {
            try {
                $conn->beginTransaction();

                // Insert Mutation Log
                $stmtMut = $conn->prepare("INSERT INTO Mutasi_Stok (barang_id, jenis_mutasi, jumlah, tanggal, keterangan, dibuat_oleh) VALUES (:barang_id, :jenis, :jumlah, :tanggal, :ket, :user_id)");
                $stmtMut->execute([
                    'barang_id' => $barang_id,
                    'jenis' => $jenis_mutasi,
                    'jumlah' => $jumlah,
                    'tanggal' => $tanggal,
                    'ket' => $keterangan,
                    'user_id' => $current_user_id
                ]);

                // Update system stock in Gudang_Stok
                if ($jenis_mutasi === 'Masuk') {
                    $stmtUpd = $conn->prepare("UPDATE Gudang_Stok SET stok_sistem = stok_sistem + :jumlah WHERE id = :barang_id");
                } elseif ($jenis_mutasi === 'Keluar') {
                    $stmtUpd = $conn->prepare("UPDATE Gudang_Stok SET stok_sistem = stok_sistem - :jumlah WHERE id = :barang_id");
                } elseif ($jenis_mutasi === 'Penyesuaian Opname') {
                    $stmtUpd = $conn->prepare("UPDATE Gudang_Stok SET stok_sistem = :jumlah WHERE id = :barang_id");
                }
                $stmtUpd->execute(['jumlah' => $jumlah, 'barang_id' => $barang_id]);

                $conn->commit();
                $success_msg = "Mutasi stok berhasil dicatat!";
            } catch (PDOException $e) {
                $conn->rollBack();
                $error_msg = "Gagal memproses mutasi: " . $e->getMessage();
            }
        }
    }
}

// Daftar kategori dasar (default) untuk form Tambah Barang Baru
$kategori_dasar = ['Alat Ukur', 'Alat Tulis', 'Inventaris Gedung', 'Dokumen K3'];

// Ambil kategori custom yang sudah pernah ditambahkan lewat "Lainnya" (belum ada di daftar dasar)
$kategori_custom_rows = $conn->query("SELECT nama_kategori FROM kategori_barang_gudang ORDER BY nama_kategori ASC")->fetchAll();
$kategori_custom = [];
foreach ($kategori_custom_rows as $row) {
    if (!in_array($row['nama_kategori'], $kategori_dasar)) {
        $kategori_custom[] = $row['nama_kategori'];
    }
}
// Gabungkan: dasar + custom + "Lainnya" di paling akhir
$kategori_options = array_merge($kategori_dasar, $kategori_custom, ['Lainnya']);

// Fetch Inventory items
$items = $conn->query("
    SELECT gs.*, jbg.nama_jenis, kbg.nama_kategori 
    FROM Gudang_Stok gs
    JOIN jenis_barang_gudang jbg ON gs.id_jenis = jbg.id_jenis
    JOIN kategori_barang_gudang kbg ON jbg.id_kategori = kbg.id_kategori
    ORDER BY gs.nama_barang ASC
")->fetchAll();

// Fetch Recent Mutations
$mutations = $conn->query("
    SELECT ms.*, gs.nama_barang, gs.kode_barang, u.nama_lengkap AS operator 
    FROM Mutasi_Stok ms
    JOIN Gudang_Stok gs ON ms.barang_id = gs.id
    JOIN Users u ON ms.dibuat_oleh = u.id
    ORDER BY ms.tanggal DESC, ms.id DESC LIMIT 10
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

    <!-- Tab Navigation -->
    <div class="arp-tab-group">
        <div class="arp-tab-nav">
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelStok' ? ' active' : '' ?>"
                data-tab-target="tabPanelStok" onclick="switchTab('tabPanelStok', this)">
                <i class="bi bi-box-seam me-1"></i> Daftar Stok
            </button>
            <button type="button" class="arp-tab-btn<?= $active_tab === 'tabPanelMutasi' ? ' active' : '' ?>"
                data-tab-target="tabPanelMutasi" onclick="switchTab('tabPanelMutasi', this)">
                <i class="bi bi-clock-history me-1"></i> Riwayat Mutasi
            </button>
        </div>

        <div class="row g-4">
            <!-- Inventory List -->
            <div class="col-12 arp-tab-panel" id="tabPanelStok" <?= $active_tab === 'tabPanelStok' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Daftar Stok Inventaris Perusahaan</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari barang..."
                                    data-table-search="tabelStok" onkeyup="handleTableSearch('tabelStok')">
                            </div>
                            <button class="btn-primary-custom" onclick="openModal('modalTambahBarang')">
                                <i class="bi bi-plus-lg"></i>Tambah Barang Baru
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelStok">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Nama Barang</th>
                                    <th>Kategori / Jenis</th>
                                    <th>Stok Sistem</th>
                                    <th>Rak</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($items) === 0): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">Belum ada barang di gudang.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $it): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($it['kode_barang']) ?></strong></td>
                                            <td><?= htmlspecialchars($it['nama_barang']) ?></td>
                                            <td><?= htmlspecialchars($it['nama_kategori']) ?> /
                                                <?= htmlspecialchars($it['nama_jenis']) ?>
                                            </td>
                                            <td><strong><?= htmlspecialchars($it['stok_sistem']) ?></strong>
                                                <?= htmlspecialchars($it['satuan']) ?></td>
                                            <td><?= htmlspecialchars($it['lokasi_rak'] ?: '-') ?></td>
                                            <td>
                                                <?php if ($it['stok_sistem'] <= $it['stok_minimum']): ?>
                                                    <span class="badge-danger">Stok Tipis</span>
                                                <?php else: ?>
                                                    <span class="badge-success">Aman</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelStok"></div>
                </div>
            </div>

            <!-- Recent Mutation Log -->
            <div class="col-12 arp-tab-panel" id="tabPanelMutasi" <?= $active_tab === 'tabPanelMutasi' ? '' : 'style="display:none;"' ?>>
                <div class="card-box">
                    <div class="table-toolbar">
                        <h5 class="table-toolbar-title fw-bold">Riwayat Mutasi Stok (Masuk/Keluar)</h5>
                        <div class="table-toolbar-actions">
                            <div class="search-box-container">
                                <i class="bi bi-search"></i>
                                <input type="text" class="search-box" placeholder="Cari mutasi..."
                                    data-table-search="tabelMutasi" onkeyup="handleTableSearch('tabelMutasi')">
                            </div>
                            <button class="btn-primary-custom" onclick="openModal('modalMutasi')">
                                <i class="bi bi-plus-lg"></i>Catat Mutasi / Stock Opname
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom" id="tabelMutasi">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Barang</th>
                                    <th>Jenis Mutasi</th>
                                    <th>Jumlah</th>
                                    <th>Keterangan</th>
                                    <th>Operator</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($mutations) === 0): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">Belum ada log mutasi.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($mutations as $m): ?>
                                        <tr>
                                            <td><?= date('d-m-Y', strtotime($m['tanggal'])) ?></td>
                                            <td><strong><?= htmlspecialchars($m['nama_barang']) ?></strong></td>
                                            <td>
                                                <?php
                                                $badgeClass = "badge-success";
                                                if ($m['jenis_mutasi'] === 'Keluar')
                                                    $badgeClass = "badge-danger";
                                                if ($m['jenis_mutasi'] === 'Penyesuaian Opname')
                                                    $badgeClass = "badge-warning";
                                                ?>
                                                <span
                                                    class="<?= $badgeClass ?>"><?= htmlspecialchars($m['jenis_mutasi']) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($m['jumlah']) ?></td>
                                            <td><?= htmlspecialchars($m['keterangan'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($m['operator']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination-custom" id="pagination-tabelMutasi"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Catat Mutasi / Stock Opname -->
    <div class="arp-modal-overlay" id="modalMutasi" onclick="closeModalOutside(event, 'modalMutasi')">
        <div class="arp-modal-box" style="max-width:520px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0">Catat Mutasi / Stock Opname</h5>
                    <small class="text-muted">Masukkan data pergerakan stok barang</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalMutasi')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="stock.php">
                    <input type="hidden" name="action" value="mutate">
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Pilih Barang Gudang *</label>
                        <select name="barang_id" class="select-custom" required>
                            <option value="">-- Pilih Barang --</option>
                            <?php foreach ($items as $it): ?>
                                <option value="<?= $it['id'] ?>"><?= htmlspecialchars($it['nama_barang']) ?> (Stok:
                                    <?= $it['stok_sistem'] ?>     <?= $it['satuan'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Jenis Mutasi *</label>
                        <select name="jenis_mutasi" class="select-custom" required>
                            <option value="Masuk">Masuk (Restock)</option>
                            <option value="Keluar">Keluar (Penggunaan Alat)</option>
                            <option value="Penyesuaian Opname">Penyesuaian Opname (Opname Fisik)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Jumlah *</label>
                        <input type="number" name="jumlah" class="form-control-custom" placeholder="Contoh: 10" min="1"
                            required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Keterangan / Alasan</label>
                        <textarea name="keterangan" class="textarea-custom"
                            placeholder="Contoh: Pengadaan APD proyek Karawang"></textarea>
                    </div>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalMutasi')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Catat Mutasi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Tambah Barang Baru -->
    <div class="arp-modal-overlay" id="modalTambahBarang" onclick="closeModalOutside(event, 'modalTambahBarang')">
        <div class="arp-modal-box" style="max-width:560px;">
            <div class="arp-modal-header">
                <div>
                    <h5 class="fw-bold mb-0">Tambah Barang Baru</h5>
                    <small class="text-muted">Daftarkan item baru ke dalam gudang inventaris</small>
                </div>
                <button class="arp-modal-close" onclick="closeModal('modalTambahBarang')">&times;</button>
            </div>
            <div class="arp-modal-body">
                <form method="POST" action="stock.php" id="formTambahBarang">
                    <input type="hidden" name="action" value="tambah_barang">

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Kode Barang / SKU *</label>
                        <input type="text" name="kode_barang" class="form-control-custom" placeholder="Contoh: ATK-0012"
                            required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Nama Barang Lengkap *</label>
                        <input type="text" name="nama_barang" class="form-control-custom"
                            placeholder="Contoh: Meteran Laser 50m" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-2">Kategori Barang *</label>
                        <select name="kategori_pilih" id="kategoriPilih" class="select-custom"
                            onchange="toggleKategoriManual()" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategori_options as $kat): ?>
                                <option value="<?= htmlspecialchars($kat) ?>"><?= htmlspecialchars($kat) ?></option>
                            <?php endforeach; ?>
                            <option value="Lainnya">+ Lainnya (ketik / tambah kategori baru)</option>
                        </select>
                    </div>

                    <div class="mb-3" id="wrapperKategoriManual" style="display:none;">
                        <label class="form-label fw-semibold mb-2">Nama Kategori Baru *</label>
                        <input type="text" name="kategori_manual" id="kategoriManual" class="form-control-custom"
                            placeholder="Ketik nama kategori baru, contoh: APD Elektrikal">
                        <small class="text-muted">Kategori baru ini akan otomatis tersimpan dan muncul di pilihan
                            berikutnya.</small>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Satuan Ukur *</label>
                            <input type="text" name="satuan" class="form-control-custom"
                                placeholder="Contoh: Pcs, Unit, Box" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Stok Sistem Awal *</label>
                            <input type="number" name="stok_awal" class="form-control-custom" min="0" value="0"
                                required>
                        </div>
                    </div>


                    <div class="mb-4">
                        <label class="form-label fw-semibold mb-2">Lokasi Penyimpanan / Rak</label>
                        <input type="text" name="lokasi_rak" class="form-control-custom"
                            placeholder="Contoh: Rak A-3, Gudang Lantai 2">
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn-secondary-custom"
                            onclick="closeModal('modalTambahBarang')">Batal</button>
                        <button type="submit" class="btn-primary-custom">Simpan Barang</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>


<script>
    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelStok', 10);
        initTablePagination('tabelMutasi', 10);
    });

    // Ganti tab aktif (Daftar Stok <-> Riwayat Mutasi) tanpa reload halaman
    // -> Menggunakan fungsi global switchTab() dari assets/js/script.js

    // Toggle input manual kategori ketika user pilih "Lainnya"
    function toggleKategoriManual() {
        const pilih = document.getElementById('kategoriPilih').value;
        const wrapper = document.getElementById('wrapperKategoriManual');
        const inputManual = document.getElementById('kategoriManual');

        if (pilih === 'Lainnya') {
            wrapper.style.display = 'block';
            inputManual.required = true;
        } else {
            wrapper.style.display = 'none';
            inputManual.required = false;
            inputManual.value = '';
        }
    }
</script>

<?php
include "../includes/footer.php";
?>