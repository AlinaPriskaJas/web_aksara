<?php
// client/pengajuan.php
session_start();
require_once "../config/koneksi.php";

// TODO: ganti dengan user_id dari sesi login sebenarnya setelah proses_login.php terhubung penuh.
$user_id = $_SESSION['user_id'] ?? 1;

// ================== MODE AJAX: AUTOCOMPLETE NAMA UNIT ==================
// pengajuan.php?ajax=cari_unit&q=f
// Mengembalikan jenis_objek_k3 yang cocok LENGKAP dengan nama_kategori (Bidang)-nya,
// supaya Bidang tidak perlu dipilih manual oleh client.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cari_unit') {
    header('Content-Type: application/json');

    $q = trim($_GET['q'] ?? '');

    if ($q === '') {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    try {
        $stmt = $conn->prepare("
            SELECT j.id_jenis, j.nama_objek, k.nama_kategori
            FROM jenis_objek_k3 j
            JOIN kategori_objek_k3 k ON k.id_kategori = j.id_kategori
            WHERE j.nama_objek LIKE :q
            ORDER BY
                CASE WHEN j.nama_objek LIKE :qstart THEN 0 ELSE 1 END,
                j.nama_objek ASC
            LIMIT 10
        ");
        $stmt->execute([
            ':q'      => '%' . $q . '%',
            ':qstart' => $q . '%',
        ]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'data' => []]);
    }
    exit;
}

// ================== MODE PROSES SUBMIT FORM ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_pengajuan'])) {

    function redirect_dengan_alert(string $type, string $message): void
    {
        $_SESSION['flash_alert'] = ['type' => $type, 'message' => $message];
        header("Location: pengajuan.php");
        exit;
    }

    // Ambil klien_id dari user yang login
    try {
        $stmt = $conn->prepare("SELECT id FROM Data_Klien WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $user_id]);
        $klien = $stmt->fetch();
    } catch (PDOException $e) {
        redirect_dengan_alert('danger', 'Gagal memuat data klien: ' . $e->getMessage());
    }

    if (!$klien) {
        redirect_dengan_alert('danger', 'Akun Anda belum terhubung dengan data perusahaan klien. Hubungi Admin.');
    }
    $klien_id = (int) $klien['id'];

    // Validasi input utama
    $nama_perusahaan_input   = trim($_POST['nama_perusahaan'] ?? '');
    $id_jenis                = isset($_POST['id_jenis']) ? (int) $_POST['id_jenis'] : 0;
    $jenis_pemeriksaan_utama = trim($_POST['jenis_pemeriksaan_utama'] ?? '');
    $tanggal_diinginkan      = trim($_POST['tanggal_diinginkan'] ?? '');
    $lokasi_objek            = trim($_POST['lokasi_objek'] ?? '');

    // ===== Baris detail tambahan (baris ke-2 dst pada kartu "Nama Unit / Alat K3") =====
    // Baris pertama SUDAH diwakili oleh id_jenis + jenis_pemeriksaan_utama (wajib, hasil autocomplete + select).
    // Baris tambahan sifatnya opsional, TAPI tetap dianggap unit terpisah yang mau diperiksa
    // (bukan cuma teks bebas) supaya 1 pengajuan bisa memuat banyak unit, dan SETIAP unit bisa
    // punya jenis pemeriksaan sendiri-sendiri (mis. unit A "Baru", unit B "Berkala").
    // detail_unit[], detail_unit_id_jenis[] & detail_unit_jenis[] SEJAJAR index-nya
    // (dikirim per baris yang sama oleh JS), jadi dipasangkan sebelum difilter.
    $detail_rows_text_raw    = $_POST['detail_unit'] ?? [];
    $detail_rows_idjenis_raw = $_POST['detail_unit_id_jenis'] ?? [];
    $detail_rows_jenis_raw   = $_POST['detail_unit_jenis'] ?? [];
    $detail_rows_text_raw    = is_array($detail_rows_text_raw) ? $detail_rows_text_raw : [];
    $detail_rows_idjenis_raw = is_array($detail_rows_idjenis_raw) ? $detail_rows_idjenis_raw : [];
    $detail_rows_jenis_raw   = is_array($detail_rows_jenis_raw) ? $detail_rows_jenis_raw : [];

    $detail_units = []; // ['nama_unit' => ..., 'id_jenis' => int|null, 'jenis_pemeriksaan' => string]
    foreach ($detail_rows_text_raw as $idx => $teks) {
        $teks = trim((string) $teks);
        if ($teks === '') continue;

        $idJenisBaris      = isset($detail_rows_idjenis_raw[$idx]) ? (int) $detail_rows_idjenis_raw[$idx] : 0;
        $jenisPeriksaBaris = trim((string) ($detail_rows_jenis_raw[$idx] ?? ''));

        $detail_units[] = [
            'nama_unit'         => $teks,
            'id_jenis'          => $idJenisBaris > 0 ? $idJenisBaris : null,
            // Kalau baris tambahan tidak diisi jenis pemeriksaannya, ikut jenis unit utama.
            'jenis_pemeriksaan' => $jenisPeriksaBaris !== '' ? $jenisPeriksaBaris : $jenis_pemeriksaan_utama,
        ];
    }
    $deskripsi_kebutuhan = implode("\n", array_column($detail_units, 'nama_unit'));

    if ($nama_perusahaan_input === '' || !$id_jenis || $jenis_pemeriksaan_utama === '' || $tanggal_diinginkan === '') {
        redirect_dengan_alert('danger', 'Mohon lengkapi Nama Perusahaan, Nama Unit/Alat K3 (pilih dari saran autocomplete), Jenis Pemeriksaan, dan Tanggal yang diinginkan.');
    }

    // ===== Ambil Jenis Objek K3 + Bidang (kategori) SEKALIGUS dari database =====
    // Bidang TIDAK diinput manual oleh client (dan tidak lagi ditampilkan ke client),
    // server yang menentukan berdasarkan id_jenis, lalu disimpan ke kolom
    // klasifikasi_objek_k3 supaya bisa dibaca Admin di halaman approval/verifikasi.
    try {
        $stmt = $conn->prepare("
            SELECT j.nama_objek, k.id_kategori, k.nama_kategori
            FROM jenis_objek_k3 j
            JOIN kategori_objek_k3 k ON k.id_kategori = j.id_kategori
            WHERE j.id_jenis = :id_jenis
        ");
        $stmt->execute([':id_jenis' => $id_jenis]);
        $jenis = $stmt->fetch();
    } catch (PDOException $e) {
        redirect_dengan_alert('danger', 'Gagal memvalidasi Nama Unit/Alat K3: ' . $e->getMessage());
    }

    if (!$jenis) {
        redirect_dengan_alert('danger', 'Nama Unit/Alat K3 tidak valid. Silakan ketik ulang dan pilih dari daftar saran yang muncul.');
    }

    $klasifikasi_objek_k3 = $jenis['nama_kategori'];
    $jenis_objek_text     = $jenis['nama_objek'];

    // Simpan ke database (transaksi)
    try {
        $conn->beginTransaction();

        // Kolom jenis_pemeriksaan di Pengajuan_Pemeriksaan diisi dari unit UTAMA saja,
        // sebagai ringkasan/legacy (dipakai halaman lama yang masih baca kolom ini).
        // Sumber kebenaran per-unit tetap di Pengajuan_Pemeriksaan_Unit.jenis_pemeriksaan.
        $stmt = $conn->prepare("
            INSERT INTO Pengajuan_Pemeriksaan
                (klien_id, nama_perusahaan, diajukan_oleh, jenis_pemeriksaan, klasifikasi_objek_k3, jenis_objek,
                 lokasi_objek, deskripsi_kebutuhan, tanggal_diinginkan, status)
            VALUES
                (:klien_id, :nama_perusahaan, :diajukan_oleh, :jenis_pemeriksaan, :klasifikasi_objek_k3, :jenis_objek,
                 :lokasi_objek, :deskripsi_kebutuhan, :tanggal_diinginkan, 'Menunggu Verifikasi')
        ");
        $stmt->execute([
            ':klien_id'             => $klien_id,
            ':nama_perusahaan'      => $nama_perusahaan_input,
            ':diajukan_oleh'        => $user_id,
            ':jenis_pemeriksaan'    => $jenis_pemeriksaan_utama,
            ':klasifikasi_objek_k3' => $klasifikasi_objek_k3,
            ':jenis_objek'          => $jenis_objek_text,
            ':lokasi_objek'         => $lokasi_objek,
            ':deskripsi_kebutuhan'  => $deskripsi_kebutuhan,
            ':tanggal_diinginkan'   => $tanggal_diinginkan,
        ]);
        $pengajuan_id = (int) $conn->lastInsertId();

        // ===== Simpan SEMUA unit (utama + tambahan) ke Pengajuan_Pemeriksaan_Unit =====
        // Ini yang membuat 1 pengajuan bisa memuat banyak unit sekaligus & terhubung
        // ke database: tiap unit jadi 1 baris, dengan id_jenis (kalau ada) dan
        // jenis_pemeriksaan SENDIRI-SENDIRI, supaya admin/approval.php bisa menampilkan
        // Bidang & Jenis Pemeriksaan tiap unit lewat JOIN.
        $stmtUnit = $conn->prepare("
            INSERT INTO Pengajuan_Pemeriksaan_Unit (pengajuan_id, id_jenis, nama_unit, jenis_pemeriksaan, urutan)
            VALUES (:pengajuan_id, :id_jenis, :nama_unit, :jenis_pemeriksaan, :urutan)
        ");

        // Unit utama (baris pertama, wajib, id_jenis & jenis_pemeriksaan sudah pasti valid)
        $stmtUnit->execute([
            ':pengajuan_id'      => $pengajuan_id,
            ':id_jenis'          => $id_jenis,
            ':nama_unit'         => $jenis_objek_text,
            ':jenis_pemeriksaan' => $jenis_pemeriksaan_utama,
            ':urutan'            => 0,
        ]);

        // Unit-unit tambahan (opsional, boleh teks bebas atau hasil pilih autocomplete,
        // masing-masing punya jenis pemeriksaan sendiri)
        $urutanUnit = 1;
        foreach ($detail_units as $unit) {
            $stmtUnit->execute([
                ':pengajuan_id'      => $pengajuan_id,
                ':id_jenis'          => $unit['id_jenis'], // null kalau diketik bebas, tidak dipilih dari saran
                ':nama_unit'         => $unit['nama_unit'],
                ':jenis_pemeriksaan' => $unit['jenis_pemeriksaan'],
                ':urutan'            => $urutanUnit,
            ]);
            $urutanUnit++;
        }

        // Buat entri Approval agar muncul di admin/approval.php
        $stmt = $conn->prepare("
            INSERT INTO Approval (jenis_pengajuan, ref_id, requester_id, level, status)
            VALUES ('Pengajuan Pemeriksaan', :ref_id, :requester_id, 1, 'Menunggu')
        ");
        $stmt->execute([
            ':ref_id'       => $pengajuan_id,
            ':requester_id' => $user_id,
        ]);

        // Upload dokumen pendukung (opsional) -> Dokumen_Digital
        if (isset($_FILES['dokumen_pendukung']) && !empty($_FILES['dokumen_pendukung']['name'][0])) {
            $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
            $max_size    = 10 * 1024 * 1024;
            $upload_dir  = "../uploads/pengajuan/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $jumlah_file = count($_FILES['dokumen_pendukung']['name']);
            for ($i = 0; $i < $jumlah_file; $i++) {
                if ($_FILES['dokumen_pendukung']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['dokumen_pendukung']['size'][$i] > $max_size) continue;

                $nama_asli = $_FILES['dokumen_pendukung']['name'][$i];
                $ext = strtolower(pathinfo($nama_asli, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext)) continue;

                $nama_file = 'pengajuan_' . $pengajuan_id . '_' . time() . '_' . $i . '.' . $ext;
                if (move_uploaded_file($_FILES['dokumen_pendukung']['tmp_name'][$i], $upload_dir . $nama_file)) {
                    $stmt = $conn->prepare("
                        INSERT INTO Dokumen_Digital
                            (nama_dokumen, kategori, file_path, modul_sumber, ref_id, klien_id, visibilitas, diupload_oleh)
                        VALUES
                            (:nama_dokumen, 'Lainnya', :file_path, 'Pengajuan_Pemeriksaan', :ref_id, :klien_id, 'Internal', :diupload_oleh)
                    ");
                    $stmt->execute([
                        ':nama_dokumen'  => $nama_asli,
                        ':file_path'     => 'uploads/pengajuan/' . $nama_file,
                        ':ref_id'        => $pengajuan_id,
                        ':klien_id'      => $klien_id,
                        ':diupload_oleh' => $user_id,
                    ]);
                }
            }
        }

        $conn->commit();
    } catch (PDOException $e) {
        $conn->rollBack();
        redirect_dengan_alert('danger', 'Gagal menyimpan pengajuan: ' . $e->getMessage());
    }

    redirect_dengan_alert('success', 'Pengajuan pemeriksaan berhasil dikirim dan menunggu verifikasi Admin.');
}

// ================== HELPER: ambil daftar nilai ENUM langsung dari struktur tabel ==================
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

// ================== MODE HALAMAN NORMAL ==================
$page_title = "Pengajuan Pemeriksaan";

$nama_perusahaan_default = "";
try {
    $stmt = $conn->prepare("SELECT nama_perusahaan FROM Data_Klien WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $user_id]);
    $klien = $stmt->fetch();
    if ($klien) {
        $nama_perusahaan_default = $klien['nama_perusahaan'];
    }
} catch (PDOException $e) {
    // Biarkan kosong jika query gagal, field tetap bisa diisi manual
}

// Ambil daftar nilai ENUM jenis_pemeriksaan dari tabel Pengajuan_Pemeriksaan_Unit (per-unit),
// fallback ke tabel Pengajuan_Pemeriksaan kalau kolom belum ter-migrasi di Unit.
$daftar_jenis_pemeriksaan = get_enum_values($conn, 'Pengajuan_Pemeriksaan_Unit', 'jenis_pemeriksaan');
if (empty($daftar_jenis_pemeriksaan)) {
    $daftar_jenis_pemeriksaan = get_enum_values($conn, 'Pengajuan_Pemeriksaan', 'jenis_pemeriksaan');
}
if (empty($daftar_jenis_pemeriksaan)) {
    $daftar_jenis_pemeriksaan = ['Pemeriksaan Baru', 'Pemeriksaan Berkala', 'Pemeriksaan Ulang', 'Pemeriksaan Khusus'];
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";

$flash = $_SESSION['flash_alert'] ?? null;
unset($_SESSION['flash_alert']);
?>

<main class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>-custom mb-3">
            <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
            <div><?= htmlspecialchars($flash['message']) ?></div>
        </div>
    <?php endif; ?>

    <div class="card-box">
        <form action="pengajuan.php" method="POST" enctype="multipart/form-data" id="formPengajuan">
            <input type="hidden" name="simpan_pengajuan" value="1">
            <div class="row g-4">
                <!-- Nama Perusahaan (INPUT MANUAL oleh klien) -->
                <div class="col-12">
                    <label class="form-label fw-semibold fs-7 mb-2">Nama Perusahaan</label>
                    <input type="text" class="form-control-custom" name="nama_perusahaan"
                        value="<?= htmlspecialchars($nama_perusahaan_default) ?>"
                        placeholder="Masukkan nama perusahaan..." required>
                </div>

                <!-- ===== SATU-SATUNYA SECTION UNTUK UNIT: Nama Unit / Alat K3 ===== -->
                <!-- Baris pertama = autocomplete wajib (menentukan id_jenis) + Jenis Pemeriksaan wajib. -->
                <!-- Baris ke-2 dst = autocomplete juga (opsional) + Jenis Pemeriksaan sendiri-sendiri,
                     karena tiap unit belum tentu jenis pemeriksaannya sama (bisa Baru & Berkala sekaligus).
                     Semua baris pakai CSS GRID dengan grid-template-columns YANG SAMA PERSIS, supaya
                     kolom "Nama Unit" dan kolom "Jenis Pemeriksaan" selalu sejajar vertikal di semua
                     baris, terlepas dari jumlah tombol (baris utama cuma copy, baris tambahan copy+hapus). -->
                <div class="col-12">
                    <div class="p-3 p-md-4" style="background: var(--bg-glass, #f8fafc); border-radius: 14px; border: 1px solid #eef1f5;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold fs-7 text-uppercase" style="letter-spacing:0.03em; color:#475569;">
                                Nama Unit / Alat K3
                            </span>
                            <span class="fs-7 text-muted" id="detailRowCounter">0 terisi</span>
                        </div>

                        <div class="mb-3">
                            <span class="fs-7 fw-semibold text-muted me-2">Template Chips (untuk baris tambahan):</span>
                            <button type="button" class="detail-chip-btn" data-prefix="Merek: ">+ Merek</button>
                            <button type="button" class="detail-chip-btn" data-prefix="Kapasitas: ">+ Kapasitas</button>
                            <button type="button" class="detail-chip-btn" data-prefix="No. Seri: ">+ No. Seri</button>
                            <button type="button" class="detail-chip-btn" data-prefix="Kode Lokasi: ">+ Kode Lokasi</button>
                        </div>

                        <!-- Label kolom, cuma tampil di layar medium ke atas supaya user tahu kolom kiri = Unit, kolom kanan = Jenis Pemeriksaan -->
                        <div class="detail-row-header d-none d-md-grid">
                            <span class="fs-7 fw-semibold text-muted">Nama Unit / Alat K3</span>
                            <span class="fs-7 fw-semibold text-muted">Jenis Pemeriksaan</span>
                            <span></span>
                            <span></span>
                        </div>

                        <!-- Baris pertama: autocomplete wajib + jenis pemeriksaan wajib -->
                        <div class="detail-row" id="rowUnitUtama">
                            <div class="unit-ac-wrapper">
                                <input type="text" class="form-control-custom unit-ac-input" id="inputNamaUnit" autocomplete="off"
                                    placeholder="Ketik nama alat, contoh: Forklift, Genset, Panel Listrik..." required>
                                <!-- id_jenis tetap disimpan di background (hidden input), hanya badge "Bidang
                                     terdeteksi" yang tidak lagi ditampilkan ke client. Nilainya akan terlihat
                                     di halaman approval Admin lewat kolom klasifikasi_objek_k3. -->
                                <input type="hidden" name="id_jenis" id="inputIdJenis" value="">
                                <div class="unit-ac-suggestbox" style="display:none; position:absolute; z-index:20; top:100%; left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; margin-top:4px; max-height:220px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.08);"></div>
                            </div>
                            <select class="select-custom detail-row-jenis" name="jenis_pemeriksaan_utama" id="selectJenisUtama" required>
                                <option value="">-- Jenis Pemeriksaan --</option>
                                <?php foreach ($daftar_jenis_pemeriksaan as $jp): ?>
                                    <option value="<?= htmlspecialchars($jp) ?>"><?= htmlspecialchars($jp) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="detail-row-btn copy-btn" title="Salin sebagai baris detail baru" id="btnCopyUnitUtama">
                                <i class="bi bi-files"></i>
                            </button>
                            <!-- Baris pertama tidak bisa dihapus. Kolom ke-4 sengaja dibiarkan kosong
                                 (bukan dihilangkan) supaya grid tetap 4 kolom & sejajar dengan baris tambahan. -->
                            <span></span>
                        </div>

                        <!-- Baris tambahan (dinamis, masing-masing dapat autocomplete + jenis pemeriksaan independen) -->
                        <div id="detailRowsContainer"></div>

                        <button type="button" class="detail-add-row-btn" id="btnTambahBarisDetail">
                            <i class="bi bi-plus-lg me-1"></i> Tambah Unit / Baris Baru
                        </button>

                        <small class="text-muted d-block mt-2">
                            Baris pertama wajib dipilih dari saran autocomplete dan wajib pilih Jenis Pemeriksaan.
                            Baris tambahan juga punya saran autocomplete dan Jenis Pemeriksaan sendiri-sendiri
                            (boleh beda dari baris utama, mis. unit ini "Baru" dan unit satunya "Berkala"). Kalau
                            Jenis Pemeriksaan baris tambahan tidak dipilih, otomatis mengikuti baris utama.
                        </small>
                    </div>
                </div>

                <!-- Tanggal yang Diinginkan -->
                <div class="col-12">
                    <label class="form-label fw-semibold fs-7 mb-2">Tanggal yang Diinginkan</label>
                    <div class="date-input-wrapper">
                        <i class="bi bi-calendar-week"></i>
                        <input type="date" class="form-control-custom" name="tanggal_diinginkan" required>
                    </div>
                </div>

                <!-- Lokasi -->
                <div class="col-12">
                    <label class="form-label fw-semibold fs-7 mb-2">Lokasi</label>
                    <input type="text" class="form-control-custom" name="lokasi_objek" placeholder="Masukkan lokasi objek K3...">
                </div>

                <!-- Upload Dokumen Pendukung -->
                <div class="col-12">
                    <label class="form-label fw-semibold fs-7 mb-2">Upload Dokumen Pendukung (Opsional)</label>
                    <div class="upload-dropzone" id="uploadDropzone">
                        <div class="upload-dropzone-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                        <div>
                            <span class="fw-semibold" style="color: var(--primary);">Drag &amp; drop file di sini</span>
                            atau <span class="fw-semibold text-decoration-underline">Pilih File</span>
                        </div>
                        <span class="fs-7 text-muted">Format: PDF, JPG, PNG (Max 10 MB)</span>
                        <input type="file" name="dokumen_pendukung[]" id="dokumenPendukung" class="d-none" accept=".pdf,.jpg,.jpeg,.png" multiple>
                        <div class="upload-dropzone-filelist" id="uploadFileList"></div>
                    </div>
                </div>

                <div class="col-12 d-flex justify-content-end gap-3 mt-2">
                    <a href="dashboard.php" class="btn-secondary-custom">Batal</a>
                    <button type="submit" class="btn-primary-custom">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</main>

<style>
.detail-chip-btn {
    display: inline-block;
    background: #fff;
    border: 1px solid #d7dee6;
    color: #334155;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 5px 14px;
    border-radius: 999px;
    margin-right: 8px;
    margin-bottom: 6px;
    cursor: pointer;
    transition: all .15s ease;
}
.detail-chip-btn:hover {
    border-color: var(--success, #16a34a);
    color: var(--success, #16a34a);
    background: #f0fdf4;
}

/* ===== GRID: kunci utama supaya kolom Unit & Jenis Pemeriksaan SELALU sejajar ===== */
/* grid-template-columns SAMA PERSIS dipakai di header label & di setiap .detail-row,
   jadi kolom 1 (Unit) & kolom 2 (Jenis Pemeriksaan) pasti lurus di semua baris,
   terlepas dari baris itu punya 1 tombol (utama) atau 2 tombol (tambahan). */
.detail-row-header,
.detail-row {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(170px, 1fr) 38px 38px;
    align-items: center;
    gap: 10px;
}
.detail-row-header {
    margin-bottom: 6px;
}
.detail-row {
    margin-bottom: 10px;
}
.detail-row .unit-ac-wrapper {
    position: relative;
    min-width: 0; /* penting supaya grid item boleh menyusut, tidak overflow */
}

/* Samakan tinggi input & select supaya top/bottom-nya rata (benar-benar "sejajar") */
.detail-row .form-control-custom,
.detail-row .select-custom {
    height: 42px;
    box-sizing: border-box;
}

.detail-row-btn {
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #64748b;
    transition: all .15s ease;
}
.detail-row-btn.copy-btn:hover { border-color: #94a3b8; background: #f1f5f9; }
.detail-row-btn.delete-btn { color: #ef4444; border-color: #fecaca; background: #fef2f2; }
.detail-row-btn.delete-btn:hover { background: #fee2e2; }
.detail-add-row-btn {
    width: 100%;
    padding: 10px;
    border: 2px dashed #b9e3c6;
    border-radius: 10px;
    background: #f6fefa;
    color: var(--success, #16a34a);
    font-weight: 700;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all .15s ease;
}
.detail-add-row-btn:hover { background: #ecfdf5; border-color: var(--success, #16a34a); }
.unit-suggestion-item:hover { background: #f8fafc; }

/* Di layar sempit (mobile), grid tetap dipakai tapi kolom dibikin lebih ringkas */
@media (max-width: 576px) {
    .detail-row-header,
    .detail-row {
        grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr) 34px 34px;
        gap: 6px;
    }
    .detail-row-btn { width: 34px; height: 34px; }
}
</style>

<script src="../assets/js/client.js"></script>

<!-- ===== Nama Unit / Alat K3: autocomplete independen per baris + baris tambahan dinamis ===== -->
<script>
    // Daftar opsi Jenis Pemeriksaan, dipakai untuk membangun <select> di baris tambahan (JS).
    const JENIS_PEMERIKSAAN_OPTIONS = <?= json_encode($daftar_jenis_pemeriksaan) ?>;
</script>
<script>
(function () {
    const form          = document.getElementById('formPengajuan');
    const container      = document.getElementById('detailRowsContainer');
    const btnTambah      = document.getElementById('btnTambahBarisDetail');
    const counterLabel   = document.getElementById('detailRowCounter');
    const chipButtons    = document.querySelectorAll('.detail-chip-btn');

    /**
     * Memasang autocomplete ke SATU input, dengan state (debounce timer,
     * hasil pencarian terakhir) yang sepenuhnya lokal/tertutup (closure)
     * untuk input tersebut saja. Ini mencegah bug "ketik F di satu baris,
     * baris lain ikut menampilkan hasil F" karena sebelumnya box saran &
     * variabel hasil pencarian dipakai bersama oleh banyak input.
     *
     * onSelect(item|null) dipanggil setiap ada perubahan pilihan:
     *  - item  -> user memilih salah satu saran (nama_objek + id_jenis + nama_kategori)
     *  - null  -> pilihan direset (user mengetik ulang / menghapus)
     */
    function attachUnitAutocomplete(inputEl, suggestBoxEl, onSelect) {
        let debounceTimer = null;
        let currentResults = [];

        function hideSuggestions() {
            suggestBoxEl.style.display = 'none';
            suggestBoxEl.innerHTML = '';
            currentResults = [];
        }

        function renderSuggestions(items) {
            currentResults = items;
            if (!items.length) { hideSuggestions(); return; }

            suggestBoxEl.innerHTML = items.map((item, idx) => `
                <div class="unit-suggestion-item" data-idx="${idx}"
                    style="padding:10px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9;">
                    <div style="font-weight:600;">${item.nama_objek}</div>
                    <div style="font-size:0.75rem; color:#64748b;">${item.nama_kategori}</div>
                </div>
            `).join('');

            suggestBoxEl.querySelectorAll('.unit-suggestion-item').forEach(el => {
                el.addEventListener('click', () => {
                    const item = items[parseInt(el.dataset.idx, 10)];
                    inputEl.value = item.nama_objek;
                    onSelect(item);
                    hideSuggestions();
                });
            });

            suggestBoxEl.style.display = 'block';
        }

        inputEl.addEventListener('input', function () {
            onSelect(null); // reset pilihan setiap kali user mengetik ulang
            const q = this.value.trim();
            clearTimeout(debounceTimer);
            if (q.length < 1) { hideSuggestions(); return; }

            debounceTimer = setTimeout(() => {
                // q di-capture lewat closure setTimeout ini, jadi permintaan
                // dari baris lain tidak akan pernah menimpa hasil baris ini.
                fetch('pengajuan.php?ajax=cari_unit&q=' + encodeURIComponent(q))
                    .then(res => res.json())
                    .then(json => {
                        // Jaga-jaga: kalau isi input sudah berubah lagi sebelum
                        // response ini datang, abaikan (hindari race condition).
                        if (inputEl.value.trim() !== q) return;
                        json.success ? renderSuggestions(json.data) : hideSuggestions();
                    })
                    .catch(() => hideSuggestions());
            }, 200);
        });

        inputEl.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === 'Tab') && currentResults.length > 0) {
                e.preventDefault();
                const item = currentResults[0];
                inputEl.value = item.nama_objek;
                onSelect(item);
                hideSuggestions();
            }
        });

        document.addEventListener('click', function (e) {
            if (!suggestBoxEl.contains(e.target) && e.target !== inputEl) hideSuggestions();
        });
    }

    // Bangun opsi <option> untuk select Jenis Pemeriksaan, dengan opsi tertentu ter-selected.
    function buildJenisOptions(selected) {
        let html = '<option value="">-- Jenis Pemeriksaan --</option>';
        html += JENIS_PEMERIKSAAN_OPTIONS.map(jp =>
            `<option value="${jp}" ${jp === selected ? 'selected' : ''}>${jp}</option>`
        ).join('');
        return html;
    }

    /* ---------- Baris utama (wajib) ---------- */
    const inputUnit        = document.getElementById('inputNamaUnit');
    const inputIdJenis     = document.getElementById('inputIdJenis');
    const selectUtamaJenis = document.getElementById('selectJenisUtama');
    const suggestUtama     = document.querySelector('#rowUnitUtama .unit-ac-suggestbox');
    const btnCopyUtama     = document.getElementById('btnCopyUnitUtama');

    // Catatan: id_jenis tetap disimpan (dibutuhkan backend untuk klasifikasi_objek_k3),
    // hanya badge "Bidang terdeteksi" yang sudah tidak ditampilkan ke client.
    attachUnitAutocomplete(inputUnit, suggestUtama, function (item) {
        inputIdJenis.value = item ? item.id_jenis : '';
    });

    form.addEventListener('submit', function (e) {
        if (!inputIdJenis.value) {
            e.preventDefault();
            alert('Mohon pilih Nama Unit/Alat K3 dari daftar saran yang muncul (ketik dulu, lalu klik salah satu pilihan).');
            inputUnit.focus();
            return;
        }
        if (!selectUtamaJenis.value) {
            e.preventDefault();
            alert('Mohon pilih Jenis Pemeriksaan untuk unit utama.');
            selectUtamaJenis.focus();
        }
    });

    /* ---------- Baris tambahan (dinamis, opsional) ---------- */
    function updateCounter() {
        const isi = [...container.querySelectorAll('input.detail-row-input')]
            .filter(inp => inp.value.trim() !== '').length;
        counterLabel.textContent = isi + ' terisi';
    }

    function addRow(prefillText = '', prefillJenis = '') {
        const row = document.createElement('div');
        row.className = 'detail-row';
        row.innerHTML = `
            <div class="unit-ac-wrapper">
                <input type="text" name="detail_unit[]" class="form-control-custom detail-row-input unit-ac-input"
                    autocomplete="off"
                    placeholder="Contoh: Forklift No. Ser_093 atau Boiler" value="${String(prefillText).replace(/"/g, '&quot;')}">
                <!-- id_jenis baris ini, index-nya sejajar dengan detail_unit[] di atas.
                     Kalau user pilih dari saran, Bidang unit ini ikut kebawa; kalau
                     cuma diketik bebas, tetap null (backend fallback teks bebas). -->
                <input type="hidden" name="detail_unit_id_jenis[]" class="detail-row-idjenis" value="">
                <div class="unit-ac-suggestbox" style="display:none; position:absolute; z-index:20; top:100%; left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; margin-top:4px; max-height:220px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.08);"></div>
            </div>
            <select name="detail_unit_jenis[]" class="select-custom detail-row-jenis">
                ${buildJenisOptions(prefillJenis)}
            </select>
            <button type="button" class="detail-row-btn copy-btn" title="Salin baris ini">
                <i class="bi bi-files"></i>
            </button>
            <button type="button" class="detail-row-btn delete-btn" title="Hapus baris ini">
                <i class="bi bi-trash"></i>
            </button>
        `;

        const input        = row.querySelector('.detail-row-input');
        const inputIdJenis  = row.querySelector('.detail-row-idjenis');
        const selectJenis   = row.querySelector('.detail-row-jenis');
        const suggestBox    = row.querySelector('.unit-ac-suggestbox');
        const copyBtn       = row.querySelector('.copy-btn');
        const delBtn        = row.querySelector('.delete-btn');

        // Autocomplete independen untuk baris ini. Baris tambahan tidak wajib
        // memilih dari saran (boleh teks bebas mis. "Merek: Toyota"), tapi kalau
        // user MEMILIH saran, id_jenis-nya ikut disimpan supaya Bidang unit ini diketahui.
        attachUnitAutocomplete(input, suggestBox, function (item) {
            inputIdJenis.value = item ? item.id_jenis : '';
        });

        input.addEventListener('input', updateCounter);
        copyBtn.addEventListener('click', () => {
            const newInput = addRow(input.value, selectJenis.value);
            newInput.focus();
            updateCounter();
        });
        delBtn.addEventListener('click', () => {
            row.remove();
            updateCounter();
        });

        container.appendChild(row);
        updateCounter();
        return input;
    }

    btnTambah.addEventListener('click', () => {
        const input = addRow('');
        input.focus();
    });

    // Tombol copy di baris utama -> buat baris detail baru berisi nama unit + jenis pemeriksaan yang sama
    btnCopyUtama.addEventListener('click', () => {
        const input = addRow(inputUnit.value, selectUtamaJenis.value);
        input.focus();
    });

    chipButtons.forEach(chip => {
        chip.addEventListener('click', () => {
            const prefix = chip.dataset.prefix;
            const input = addRow(prefix);
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        });
    });

    updateCounter();
})();
</script>

<?php
include "../includes/footer.php";
?>