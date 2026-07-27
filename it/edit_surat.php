<?php
// ahlik3/edit_surat.php — Edit surat keluar yang sudah pernah dibuat.
// Memanfaatkan ulang proses generate surat yang sudah ada (generateSuratDocx dkk
// di includes/functions.php) -- TIDAK membuat baris/nomor surat baru, hanya
// meng-update record & meng-generate ulang berkas .docx-nya.
require_once "../config/koneksi.php";

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$pdo = $conn;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once "../includes/functions.php";

$page_title = "Edit Surat";
$current_user_id = $_SESSION['user_id'];

if (!function_exists('isKolomHarga')) {
    function isKolomHarga(string $namaKolom): bool
    {
        return (bool) preg_match('/harga/i', $namaKolom);
    }
}
if (!function_exists('isKolomQty')) {
    function isKolomQty(string $namaKolom): bool
    {
        return (bool) preg_match('/qty|jumlah/i', $namaKolom);
    }
}
if (!function_exists('badgeStatus')) {
    function badgeStatus(string $status): string
    {
        $map = [
            'Terkirim' => 'badge-success',
            'Selesai' => 'badge-success',
            'Disetujui' => 'badge-success',
            'Diproses' => 'badge-warning',
            'Didisposisi' => 'badge-warning',
            'Menunggu Persetujuan' => 'badge-warning',
            'Baru' => 'badge-warning',
            'Ditolak' => 'badge-danger',
            'Draft' => 'badge-secondary',
            'Diarsipkan' => 'badge-secondary',
        ];
        return $map[$status] ?? 'badge-warning';
    }
}

$surat_id = (int) ($_GET['id'] ?? $_POST['surat_id'] ?? 0);
$error_msg = "";
$success_msg = "";

$stmtSurat = $pdo->prepare("SELECT * FROM surat WHERE id = ?");
$stmtSurat->execute([$surat_id]);
$surat = $stmtSurat->fetch();

if (!$surat) {
    header("Location: surat.php?tab=surat");
    exit;
}
if ($surat['arah'] !== 'Keluar') {
    // Surat masuk tidak melalui proses generate template, tidak bisa diedit di sini.
    header("Location: surat.php?tab=masuk");
    exit;
}
if ((int) $surat['dibuat_oleh'] !== (int) $current_user_id) {
    // Hanya boleh mengedit surat buatan sendiri (sesuai hak akses existing).
    header("Location: surat.php?tab=surat");
    exit;
}

$isiDataAsli = json_decode($surat['isi_data'] ?? '', true) ?: [];

// ==========================================
// [SIMPAN PERUBAHAN] regenerate docx + update record surat (bukan insert baru)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_edit_surat') {
    $isPreviewOnly = ($_POST['preview_only'] ?? '') === '1';

    if (!$isPreviewOnly) {
        $kodeIdPost = (int) ($_POST['kode_id'] ?? 0);
        $templateIdPost = (int) ($_POST['template_id'] ?? 0);
        try {
            $stmtK = $pdo->prepare("SELECT k.*, t.id AS template_id, t.file_path, t.format
                                    FROM kode_surat k
                                    JOIN kode_template kt ON kt.kode_id = k.id AND kt.template_id = ?
                                    JOIN template_master t ON t.id = kt.template_id
                                    WHERE k.id = ?");
            $stmtK->execute([$templateIdPost, $kodeIdPost]);
            $kode = $stmtK->fetch();

            if (!$kode || !$kode['file_path']) {
                throw new RuntimeException("Kombinasi jenis surat & template ini tidak/belum terhubung.");
            }
            if (!is_file(BASE_PATH . '/' . $kode['file_path'])) {
                throw new RuntimeException("File template master tidak ditemukan di storage.");
            }
            if ($kode['format'] !== 'word_pdf') {
                throw new RuntimeException("Template ini bukan file Word (.docx), tidak bisa digenerate otomatis lewat form ini.");
            }

            $nomorBaru = trim($_POST['nomor_surat'] ?? '');
            if ($nomorBaru === '') {
                throw new RuntimeException("Nomor surat wajib diisi.");
            }
            if ($nomorBaru !== $surat['nomor']) {
                $cekNomor = $pdo->prepare("SELECT id FROM surat WHERE nomor = ? AND id != ?");
                $cekNomor->execute([$nomorBaru, $surat_id]);
                if ($cekNomor->fetch()) {
                    throw new RuntimeException("Nomor surat \"$nomorBaru\" sudah dipakai surat lain.");
                }
            }

            $dataForm = [];
            foreach ($_POST['dinamis'] ?? [] as $fieldName => $fieldValue) {
                $fieldValue = trim((string) $fieldValue);
                if (preg_match('/tanggal|tgl/i', $fieldName) && $fieldValue !== '') {
                    $fieldValue = formatTanggalIndonesia($fieldValue);
                }
                $dataForm[$fieldName] = $fieldValue;
            }

            $items = [];
            foreach ($_POST['items'] ?? [] as $baris) {
                $baris = array_map('trim', (array) $baris);
                $adaIsi = false;
                foreach ($baris as $v) {
                    if ($v !== '') {
                        $adaIsi = true;
                        break;
                    }
                }
                if ($adaIsi) {
                    $items[] = $baris;
                }
            }

            $blocksData = [];
            foreach ($_POST['blok'] ?? [] as $namaBlok => $barisList) {
                foreach ((array) $barisList as $baris) {
                    $baris = array_map('trim', (array) $baris);
                    $adaIsi = false;
                    foreach ($baris as $v) {
                        if ($v !== '') {
                            $adaIsi = true;
                            break;
                        }
                    }
                    if ($adaIsi) {
                        $blocksData[$namaBlok][] = $baris;
                    }
                }
            }

            // Perihal & Tujuan sekarang PRIORITAS diambil dari input manual di form
            // edit (perihal_manual / tujuan_manual). Kalau dikosongkan, baru fallback
            // ke hasil scan otomatis dari Word / data dinamis seperti sebelumnya.
            $perihalManual = trim($_POST['perihal_manual'] ?? '');
            $tujuanManual  = trim($_POST['tujuan_manual'] ?? '');


            // ===== Reuse proses generate surat yang sudah ada =====
            $fileHasilBaru = generateSuratDocx(
                BASE_PATH . '/' . $kode['file_path'],
                $dataForm, $items, $nomorBaru, $blocksData, $kode['nama'],
                $tujuanManual !== '' ? $tujuanManual : null   // <-- BARU
            );
            
            $perihalDariWord = extractPerihalFromDocxText(BASE_PATH . '/' . $fileHasilBaru);
            $perihalSimpan = $perihalManual !== ''
                ? $perihalManual
                : ($perihalDariWord
                    ?? $dataForm['perihal']
                    ?? ($kode['nama'] ?? '-'));

            $tujuanSimpan = $tujuanManual !== ''
                ? $tujuanManual
                : ($dataForm['instansi_tujuan']
                    ?? $dataForm['tujuan']
                    ?? $dataForm['nama_perusahaan']
                    ?? $dataForm['nama_perusahaan_tujuan']
                    ?? $dataForm['item_nama_perusahaan']
                    ?? '-');

            $isiDataDisimpan = $dataForm;
            if (!empty($items)) {
                $isiDataDisimpan['__items'] = $items;
            }
            if (!empty($blocksData)) {
                $isiDataDisimpan['__blok'] = $blocksData;
            }

            $fileLama = $surat['file_hasil'];

            $upd = $pdo->prepare("UPDATE surat SET nomor = ?, kode_id = ?, template_id = ?, perihal = ?, tujuan = ?, file_hasil = ?, isi_data = ? WHERE id = ?");
            $upd->execute([
                $nomorBaru,
                $kodeIdPost,
                $templateIdPost,
                $perihalSimpan,
                $tujuanSimpan,
                $fileHasilBaru,
                json_encode($isiDataDisimpan, JSON_UNESCAPED_UNICODE),
                $surat_id,
            ]);

            // Kalau nama berkas berubah (karena nomor/perusahaan berubah), hapus berkas lama biar tidak numpuk file yatim.
            if ($fileLama && $fileLama !== $fileHasilBaru && is_file(BASE_PATH . '/' . $fileLama)) {
                @unlink(BASE_PATH . '/' . $fileLama);
            }

            // Muat ulang data surat supaya form & pratinjau menampilkan hasil terbaru.
            $stmtSurat->execute([$surat_id]);
            $surat = $stmtSurat->fetch();
            $isiDataAsli = json_decode($surat['isi_data'] ?? '', true) ?: [];

            $success_msg = "Perubahan berhasil disimpan. Database, berkas surat, dan pratinjau sudah diperbarui.";
        } catch (Throwable $e) {
            $error_msg = "Gagal menyimpan perubahan: " . $e->getMessage();
        }
    }
}

// ==========================================
// [DATA] Daftar jenis surat & template (sama seperti tab Buat Surat)
// ==========================================
$daftar_kode = $pdo->query("SELECT * FROM kode_surat ORDER BY nama")->fetchAll();

$template_per_kode = [];
$rowsTplPerKode = $pdo->query("SELECT kt.id AS kode_template_id, kt.kode_id, kt.template_id, kt.is_default,
                                       t.nama AS nama_template, t.deskripsi, t.format
                                FROM kode_template kt
                                JOIN template_master t ON t.id = kt.template_id
                                ORDER BY kt.is_default DESC, t.nama ASC")->fetchAll();
foreach ($rowsTplPerKode as $r) {
    $template_per_kode[$r['kode_id']][] = $r;
}
$daftar_kode_dengan_template = array_values(array_filter(
    $daftar_kode,
    fn($k) => !empty($template_per_kode[$k['id']])
));

// Jenis surat / template yang aktif diedit: dari GET (kalau user ganti template),
// atau default dari data surat aslinya.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_edit_surat') {
    $kodeIdTerpilih = (int) ($_POST['kode_id'] ?? $surat['kode_id']);
    $templateIdTerpilih = (int) ($_POST['template_id'] ?? $surat['template_id']);
} else {
    $kodeIdTerpilih = (int) ($_GET['kode_id'] ?? $surat['kode_id']);
    $templateIdTerpilih = (int) ($_GET['template_id'] ?? $surat['template_id']);
}

$kodeTerpilih = null;
$fields_dinamis = [];
$fields_tabel = [];
$fields_blok = [];
if ($kodeIdTerpilih && $templateIdTerpilih) {
    $stmtF = $pdo->prepare("SELECT k.*, t.id AS template_id, t.nama AS nama_template, t.file_path, t.format, t.fields_json
                            FROM kode_surat k
                            JOIN kode_template kt ON kt.kode_id = k.id AND kt.template_id = ?
                            JOIN template_master t ON t.id = kt.template_id
                            WHERE k.id = ?");
    $stmtF->execute([$templateIdTerpilih, $kodeIdTerpilih]);
    $kodeTerpilih = $stmtF->fetch();

    if ($kodeTerpilih && !empty($kodeTerpilih['fields_json'])) {
        $decoded = json_decode($kodeTerpilih['fields_json'], true) ?: [];
        if (isset($decoded['fields']) || isset($decoded['table_fields'])) {
            $fields_dinamis = $decoded['fields'] ?? [];
            $fields_tabel = $decoded['table_fields'] ?? [];
            $fields_blok = $decoded['blocks'] ?? [];
        } else {
            $fields_dinamis = $decoded;
        }

        if (defined('FIELD_OTOMATIS_SISTEM')) {
            $fields_dinamis = array_values(array_filter(
                $fields_dinamis,
                fn($f) => !in_array(strtolower($f['field'] ?? ''), FIELD_OTOMATIS_SISTEM, true)
            ));
        }
    }
}

$file_template_hilang = $kodeTerpilih && !is_file(BASE_PATH . '/' . $kodeTerpilih['file_path']);

$auto_fields_template = ($kodeTerpilih && !$file_template_hilang && $kodeTerpilih['format'] === 'word_pdf')
    ? scanAutoFieldsFromDocx(BASE_PATH . '/' . $kodeTerpilih['file_path'])
    : [];
$ada_total = in_array('total', $auto_fields_template, true);
$ada_ppn = in_array('ppn', $auto_fields_template, true);
$ada_pph23 = in_array('pph_23', $auto_fields_template, true);
$ada_total_bayar = in_array('total_bayar', $auto_fields_template, true);
$ada_ringkasan_total = $ada_total || $ada_ppn || $ada_pph23 || $ada_total_bayar;

// Prefill: dari $_POST (kalau baru submit/preview), kalau tidak ada -> dari isi_data
// surat yang sudah tersimpan (inilah yang membuat form otomatis terisi / prefill).
$nilai_dinamis = [];
foreach ($fields_dinamis as $f) {
    $nilai_dinamis[$f['field']] = $_POST['dinamis'][$f['field']] ?? ($isiDataAsli[$f['field']] ?? '');
}

$nilai_items = $_POST['items'] ?? ($isiDataAsli['__items'] ?? []);
if (empty($nilai_items) && !empty($fields_tabel)) {
    $barisKosong = [];
    foreach ($fields_tabel as $kolom) {
        $barisKosong[$kolom['field']] = '';
    }
    $nilai_items = [$barisKosong];
}

$nilai_blok = $_POST['blok'] ?? ($isiDataAsli['__blok'] ?? []);
foreach ($fields_blok as $namaBlok => $daftarFieldBlok) {
    if (empty($nilai_blok[$namaBlok])) {
        $barisKosongBlok = [];
        foreach ($daftarFieldBlok as $kolom) {
            $barisKosongBlok[$kolom['field']] = '';
        }
        $nilai_blok[$namaBlok] = [$barisKosongBlok];
    }
}

$tabel_item_punya_harga = false;
foreach ($fields_tabel as $kolom) {
    if (isKolomHarga($kolom['field'])) {
        $tabel_item_punya_harga = true;
        break;
    }
}

include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <a href="surat.php?tab=surat" class="text-secondary text-xs">
                <i class="bi bi-arrow-left"></i> Kembali ke Daftar Surat Keluar
            </a>
            <h4 class="fw-bold mt-2 mb-0">Edit Surat <span style="font-family:monospace;"><?= e($surat['nomor']) ?></span>
            </h4>
        </div>
        <span class="<?= badgeStatus($surat['status'] ?? '') ?>"><?= e($surat['status']) ?></span>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success-custom align-items-center">
            <i class="bi bi-check-circle-fill fs-5"></i>
            <div><?= e($success_msg) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger-custom align-items-center">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?= e($error_msg) ?></div>
        </div>
    <?php endif; ?>

            <div class="buat-surat-grid">

                <section class="card-box">
                    <h5 class="fw-bold mb-3">Edit Surat</h5>

                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Jenis Surat</label>
                            <select id="pilih-jenis-surat" class="select-custom" disabled>
                                <option value="">-- Pilih jenis surat --</option>
                                <?php foreach ($daftar_kode_dengan_template as $k): ?>
                                    <option value="<?= (int) $k['id'] ?>" <?= ($kodeIdTerpilih === (int) $k['id']) ? 'selected' : '' ?>>
                                        <?= e($k['kode'] . ' (' . $k['nama'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-secondary text-xs d-block mt-1">Jenis surat tidak dapat diubah setelah surat dibuat.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold mb-2">Template</label>
                            <select id="pilih-template-surat" class="select-custom" disabled>
                                <option value="">-- Pilih template --</option>
                                <?php if ($kodeIdTerpilih): ?>
                                    <?php foreach (($template_per_kode[$kodeIdTerpilih] ?? []) as $tpl): ?>
                                        <option value="<?= (int) $tpl['template_id'] ?>" <?= ($templateIdTerpilih === (int) $tpl['template_id']) ? 'selected' : '' ?>>
                                            <?= e($tpl['nama_template']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <small class="text-secondary text-xs d-block mt-1">Template tidak dapat diubah setelah surat dibuat.</small>
                        </div>
                    </div>
                    <?php if (empty($daftar_kode_dengan_template)): ?>
                        <p class="text-secondary text-xs mb-3">Belum ada jenis surat yang punya template. Silakan
                            upload template lewat Admin terlebih dahulu.
                        </p>
                    <?php else: ?>
                    <?php endif; ?>

                    <script id="data-template-per-kode" type="application/json">
<?php
$dataUntukJs = [];
foreach ($daftar_kode_dengan_template as $k) {
    $tplTerhubung = $template_per_kode[$k['id']] ?? [];
    $dataUntukJs[(int) $k['id']] = array_map(function ($tpl) {
        return ['id' => (int) $tpl['template_id'], 'nama' => $tpl['nama_template']];
    }, $tplTerhubung);
}
echo json_encode($dataUntukJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
?>
                    </script>
                

                    <?php if (!$kodeTerpilih && $kodeIdTerpilih && $templateIdTerpilih): ?>
                        <div class="alert alert-danger-custom py-2 px-3 text-xs">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div>Kombinasi jenis surat &amp; template ini tidak ditemukan / tidak terhubung.
                                <a href="edit_surat.php?id=<?= (int) $surat_id ?>">Pilih ulang</a>.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($kodeTerpilih && $file_template_hilang): ?>
                        <div class="alert alert-danger-custom text-xs">
                            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                            <div>
                                <p class="fw-semibold mb-1">File template tidak ditemukan di storage</p>
                                <p class="mb-1">Template untuk jenis surat <b><?= e($kodeTerpilih['kode']) ?></b>
                                    (<?= e($kodeTerpilih['nama_template'] ?? '-') ?>) sudah tidak ada filenya. Silakan
                                    upload ulang lewat tab <b>Upload Template</b>.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($kodeTerpilih && $kodeTerpilih['format'] !== 'word_pdf' && !$file_template_hilang): ?>
                        <p class="text-secondary mb-0">Template ini bukan file Word (.docx), tidak bisa digenerate
                            otomatis lewat form ini.</p>
                    <?php endif; ?>

                    <?php if ($kodeTerpilih && !$file_template_hilang && $kodeTerpilih['format'] === 'word_pdf'): ?>
                        <hr>
                        <form method="POST" action="edit_surat.php?id=<?= (int) $surat_id ?>" id="form-edit-surat">
                            <input type="hidden" name="aksi" value="simpan_edit_surat">
                            <input type="hidden" name="surat_id" value="<?= (int) $surat_id ?>">
                            <input type="hidden" name="kode_id" value="<?= (int) $kodeTerpilih['id'] ?>">
                            <input type="hidden" name="template_id" value="<?= (int) $kodeTerpilih['template_id'] ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">Jenis surat / Kode</label>
                                    <input type="text" class="form-control-custom field-readonly"
                                        value="<?= e($kodeTerpilih['nama']) ?> (<?= e($kodeTerpilih['kode']) ?>)" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">Nomor Surat</label>
                                    <input type="text" name="nomor_surat" class="form-control-custom"
                                        style="font-family:monospace;"
                                        value="<?= e($_POST['nomor_surat'] ?? $surat['nomor']) ?>" required>
                                    <!-- <small class="text-secondary text-xs d-block mt-1">Ubah hanya jika benar-benar
                                        perlu -- nomor ini sudah dipakai sebagai identitas resmi surat.</small> -->
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Perihal <!-- <small class="text-secondary fw-normal">(manual, akan tersimpan ke daftar surat)</small> -->
                                    </label>
                                    <input type="text" name="perihal_manual" class="form-control-custom"
                                        placeholder="Contoh: Penawaran Harga Riksa Uji Alat"
                                        value="<?= e($_POST['perihal_manual'] ?? $surat['perihal'] ?? '') ?>">
                                    <small class="text-secondary text-xs d-block mt-1">
                                        Kosongkan untuk memakai deteksi otomatis dari kata "Perihal :" di dalam Word (jika ada).
                                    </small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold mb-2">
                                        Tujuan <!-- <small class="text-secondary fw-normal">(manual, akan tersimpan ke daftar surat)</small> -->
                                    </label>
                                    <input type="text" name="tujuan_manual" class="form-control-custom"
                                        placeholder="Contoh: PT Aksara Riksa Prima"
                                        value="<?= e($_POST['tujuan_manual'] ?? $surat['tujuan'] ?? '') ?>">
                                    <small class="text-secondary text-xs d-block mt-1">
                                        Kosongkan untuk memakai deteksi otomatis dari field template (${nama_perusahaan}, ${tujuan}, dll).
                                    </small>
                                </div>
                            </div>



                            <?php if (!empty($kodeTerpilih['deskripsi'])): ?>
                                <div class="alert alert-success-custom text-xs mb-3">
                                    <i class="bi bi-info-circle-fill"></i>
                                    <div><span class="fw-semibold">Deskripsi:</span> <?= nl2br(e($kodeTerpilih['deskripsi'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (empty($fields_dinamis) && empty($fields_tabel) && empty($fields_blok)): ?>
                                <div class="alert alert-danger-custom text-xs">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <div>Template ini belum punya placeholder <code>${...}</code> yang terbaca. Upload
                                        ulang file .docx yang sudah berisi placeholder seperti <code>${perihal}</code>
                                        lewat tab Upload Template.</div>
                                </div>
                            <?php endif; ?>

                            <div class="row g-3">
                                <?php foreach ($fields_dinamis as $f): ?>
                                    <?php $isTanggal = (bool) preg_match('/tanggal|tgl/i', $f['field']); ?>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold mb-2"><?= e($f['label']) ?></label>
                                        <input type="<?= $isTanggal ? 'date' : 'text' ?>" name="dinamis[<?= e($f['field']) ?>]"
                                            class="form-control-custom" value="<?= e($nilai_dinamis[$f['field']] ?? '') ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php foreach ($fields_blok as $namaBlok => $daftarFieldBlok): ?>
                                <?php if (empty($daftarFieldBlok))
                                    continue; ?>
                                <div class="blok-box mt-3" data-blok-wrapper="<?= e($namaBlok) ?>">
                                    <label
                                        class="form-label fw-semibold mb-2 d-block"><?= e(labelFromFieldName($namaBlok)) ?></label>
                                    <div id="blok-<?= e($namaBlok) ?>-body">
                                        <?php foreach (($nilai_blok[$namaBlok] ?? [[]]) as $idxBarisBlok => $barisBlok): ?>
                                            <div class="blok-baris" data-baris-index="<?= (int) $idxBarisBlok ?>">
                                                <?php foreach ($daftarFieldBlok as $kolomBlok): ?>
                                                    <input type="text"
                                                        name="blok[<?= e($namaBlok) ?>][<?= (int) $idxBarisBlok ?>][<?= e($kolomBlok['field']) ?>]"
                                                        placeholder="<?= e($kolomBlok['label']) ?>"
                                                        value="<?= e($barisBlok[$kolomBlok['field']] ?? '') ?>"
                                                        class="form-control-custom">
                                                <?php endforeach; ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm tombol-hapus-baris-blok">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm tombol-tambah-blok"
                                        data-blok="<?= e($namaBlok) ?>">
                                        <i class="bi bi-plus-lg"></i> Tambah
                                    </button>
                                </div>
                                <script>
                                    (function () {
                                        var namaBlok = <?= json_encode($namaBlok) ?>;
                                        var fieldList = <?= json_encode(array_column($daftarFieldBlok, 'field')) ?>;
                                        var labelList = <?= json_encode(array_column($daftarFieldBlok, 'label')) ?>;
                                        var body = document.getElementById('blok-' + namaBlok + '-body');
                                        var tombolTambah = document.querySelector('.tombol-tambah-blok[data-blok="' + namaBlok + '"]');
                                        if (!body || !tombolTambah) return;

                                        var idxBerikutnya = body.querySelectorAll('.blok-baris').length;

                                        function pasangTombolHapus(barisEl) {
                                            var btn = barisEl.querySelector('.tombol-hapus-baris-blok');
                                            if (!btn) return;
                                            btn.addEventListener('click', function () {
                                                if (body.querySelectorAll('.blok-baris').length > 1) {
                                                    barisEl.remove();
                                                } else {
                                                    barisEl.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
                                                }
                                            });
                                        }

                                        body.querySelectorAll('.blok-baris').forEach(pasangTombolHapus);

                                        tombolTambah.addEventListener('click', function () {
                                            var div = document.createElement('div');
                                            div.className = 'blok-baris';
                                            div.setAttribute('data-baris-index', idxBerikutnya);
                                            fieldList.forEach(function (f, i) {
                                                var inp = document.createElement('input');
                                                inp.type = 'text';
                                                inp.name = 'blok[' + namaBlok + '][' + idxBerikutnya + '][' + f + ']';
                                                inp.placeholder = labelList[i];
                                                inp.className = 'form-control-custom';
                                                div.appendChild(inp);
                                            });
                                            var btnHapus = document.createElement('button');
                                            btnHapus.type = 'button';
                                            btnHapus.className = 'btn btn-outline-danger btn-sm tombol-hapus-baris-blok';
                                            btnHapus.innerHTML = '<i class="bi bi-x-lg"></i>';
                                            div.appendChild(btnHapus);
                                            body.appendChild(div);
                                            pasangTombolHapus(div);
                                            idxBerikutnya++;
                                        });
                                    })();
                                </script>
                            <?php endforeach; ?>

                            <?php if (!empty($fields_tabel)): ?>
                                <div class="mt-3">
                                    <label class="form-label fw-semibold mb-2">Rincian Item</label>
                                    <div class="table-responsive-custom">
                                        <table class="table-custom" id="tabel-item">
                                            <thead>
                                                <tr>
                                                    <th style="width:36px;">No</th>
                                                    <?php foreach ($fields_tabel as $kolom): ?>
                                                        <th><?= e($kolom['label']) ?></th>
                                                    <?php endforeach; ?>
                                                    <?php if ($tabel_item_punya_harga): ?>
                                                        <th style="text-align:right;">Sub Total</th>
                                                    <?php endif; ?>
                                                    <th style="width:36px;"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="tabel-item-body">
                                                <?php foreach ($nilai_items as $idxBaris => $baris): ?>
                                                    <tr class="baris-item" data-baris-index="<?= (int) $idxBaris ?>">
                                                        <td class="nomor-baris">1</td>
                                                        <?php foreach ($fields_tabel as $kolom): ?>
                                                            <?php
                                                            $isHarga = isKolomHarga($kolom['field']);
                                                            $isQty = isKolomQty($kolom['field']);
                                                            $placeholderKolom = $isHarga ? 'cth: 6055000' : ($isQty ? 'cth: 3 unit / 5 orang' : '');
                                                            ?>
                                                            <td>
                                                                <input type="text"
                                                                    name="items[<?= (int) $idxBaris ?>][<?= e($kolom['field']) ?>]"
                                                                    data-kolom="<?= e($kolom['field']) ?>" <?= $isHarga ? 'data-tipe="harga"' : '' ?>
                                                                    placeholder="<?= e($placeholderKolom) ?>"
                                                                    class="form-control-custom"
                                                                    value="<?= e($baris[$kolom['field']] ?? '') ?>">
                                                            </td>
                                                        <?php endforeach; ?>
                                                        <?php if ($tabel_item_punya_harga): ?>
                                                            <td class="subtotal-baris" style="text-align:right; font-family:monospace;">
                                                                -</td>
                                                        <?php endif; ?>
                                                        <td style="text-align:center;">
                                                            <button type="button"
                                                                class="btn btn-outline-danger btn-sm tombol-hapus-baris">
                                                                <i class="bi bi-x-lg"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" id="tombol-tambah-baris" class="btn btn-outline-primary btn-sm mt-2">
                                        <i class="bi bi-plus-lg"></i> Tambah Baris
                                    </button>
                                    <?php if ($tabel_item_punya_harga): ?>
                                        <p class="text-secondary text-xs mt-2 mb-0">Subtotal, Total, PPN &amp; PPH
                                            dihitung otomatis oleh sistem saat surat disimpan.</p>
                                    <?php endif; ?>
                                </div>

                                <template id="template-baris-item">
                                    <tr class="baris-item" data-baris-index="__IDX__">
                                        <td class="nomor-baris">1</td>
                                        <?php foreach ($fields_tabel as $kolom): ?>
                                            <?php
                                            $isHarga = isKolomHarga($kolom['field']);
                                            $isQty = isKolomQty($kolom['field']);
                                            $placeholderKolom = $isHarga ? 'cth: 6055000' : ($isQty ? 'cth: 3 unit / 5 orang' : '');
                                            ?>
                                            <td>
                                                <input type="text" name="items[__IDX__][<?= e($kolom['field']) ?>]"
                                                    data-kolom="<?= e($kolom['field']) ?>" <?= $isHarga ? 'data-tipe="harga"' : '' ?>
                                                    placeholder="<?= e($placeholderKolom) ?>" class="form-control-custom" value="">
                                            </td>
                                        <?php endforeach; ?>
                                        <?php if ($tabel_item_punya_harga): ?>
                                            <td class="subtotal-baris" style="text-align:right; font-family:monospace;">-</td>
                                        <?php endif; ?>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn btn-outline-danger btn-sm tombol-hapus-baris">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </template>

                                <script>
                                    (function () {
                                        var tbody = document.getElementById('tabel-item-body');
                                        var tpl = document.getElementById('template-baris-item');
                                        var tombolTambah = document.getElementById('tombol-tambah-baris');
                                        if (!tbody || !tpl || !tombolTambah) return;

                                        var idxBerikutnya = tbody.querySelectorAll('tr.baris-item').length;

                                        function nomorUlangBaris() {
                                            tbody.querySelectorAll('tr.baris-item').forEach(function (tr, i) {
                                                tr.querySelector('.nomor-baris').textContent = i + 1;
                                            });
                                        }

                                        function parseAngkaJsLokal(teks) {
                                            teks = String(teks || '').trim();
                                            var m = teks.match(/-?\d[\d.,]*/);
                                            if (!m) return null;
                                            var angka = m[0];
                                            if (angka.indexOf(',') !== -1 && angka.indexOf('.') !== -1) {
                                                angka = angka.replace(/\./g, '').replace(',', '.');
                                            } else if (angka.indexOf(',') !== -1) {
                                                angka = angka.replace(',', '.');
                                            } else {
                                                var bagian = angka.split('.');
                                                if (bagian.length > 1 && bagian[bagian.length - 1].length === 3) {
                                                    angka = angka.split('.').join('');
                                                }
                                            }
                                            var hasil = parseFloat(angka);
                                            return isNaN(hasil) ? null : hasil;
                                        }

                                        function formatRupiahJsLokal(angka) {
                                            var bulat = Math.round(angka);
                                            var negatif = bulat < 0;
                                            bulat = Math.abs(bulat);
                                            var str = bulat.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                                            return (negatif ? '-' : '') + 'Rp. ' + str;
                                        }

                                        function hitungSubtotalBaris(tr) {
                                            var qty = null, harga = null;
                                            tr.querySelectorAll('input[data-kolom]').forEach(function (inp) {
                                                var nama = inp.getAttribute('data-kolom');
                                                if (qty === null && /qty|jumlah/i.test(nama)) qty = parseAngkaJsLokal(inp.value);
                                                if (harga === null && /harga/i.test(nama)) harga = parseAngkaJsLokal(inp.value);
                                            });
                                            var elSubtotal = tr.querySelector('.subtotal-baris');
                                            if (!elSubtotal) return;
                                            elSubtotal.textContent = (qty !== null && harga !== null) ? formatRupiahJsLokal(qty * harga) : '-';
                                        }

                                        function formatRibuan(str) {
                                            var angkaSaja = str.replace(/\D/g, '');
                                            if (angkaSaja === '') return '';
                                            return angkaSaja.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                                        }

                                        function pasangAutoFormatHarga(tr) {
                                            tr.querySelectorAll('input[data-tipe="harga"]').forEach(function (inp) {
                                                if (inp.value) inp.value = formatRibuan(inp.value);
                                                inp.addEventListener('input', function () {
                                                    var posisiKursorDariBelakang = this.value.length - this.selectionStart;
                                                    this.value = formatRibuan(this.value);
                                                    var posisiBaru = this.value.length - posisiKursorDariBelakang;
                                                    this.setSelectionRange(posisiBaru, posisiBaru);
                                                });
                                            });
                                        }

                                        function pasangTombolHapus(tr) {
                                            var btn = tr.querySelector('.tombol-hapus-baris');
                                            btn.addEventListener('click', function () {
                                                if (tbody.querySelectorAll('tr.baris-item').length > 1) {
                                                    tr.remove();
                                                    nomorUlangBaris();
                                                } else {
                                                    tr.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
                                                    hitungSubtotalBaris(tr);
                                                }
                                                if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat();
                                            });
                                        }

                                        tbody.querySelectorAll('tr.baris-item').forEach(function (tr) {
                                            pasangTombolHapus(tr);
                                            pasangAutoFormatHarga(tr);
                                            hitungSubtotalBaris(tr);
                                        });

                                        tbody.addEventListener('input', function (e) {
                                            if (e.target && e.target.matches('input[data-kolom]')) {
                                                var tr = e.target.closest('tr.baris-item');
                                                if (tr) hitungSubtotalBaris(tr);
                                                if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat();
                                            }
                                        });

                                        tombolTambah.addEventListener('click', function () {
                                            var html = tpl.innerHTML.replace(/__IDX__/g, idxBerikutnya);
                                            var sementara = document.createElement('tbody');
                                            sementara.innerHTML = html;
                                            var trBaru = sementara.querySelector('tr');
                                            tbody.appendChild(trBaru);
                                            pasangTombolHapus(trBaru);
                                            pasangAutoFormatHarga(trBaru);
                                            hitungSubtotalBaris(trBaru);
                                            nomorUlangBaris();
                                            idxBerikutnya++;
                                            if (window.hitungUlangTotalSurat) window.hitungUlangTotalSurat();
                                        });
                                    })();
                                </script>
                            <?php endif; ?>

                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" name="preview_only" value="1" class="btn-secondary-custom">
                                    <i class="bi bi-arrow-repeat"></i> Update Preview
                                </button>
                                <button type="submit" class="btn-primary-custom">
                                    <i class="bi bi-save"></i> Simpan Perubahan
                                </button>
                            </div>
                            <p class="text-secondary text-xs mt-2">"Simpan Perubahan" akan meng-generate ulang berkas .docx dari
                                template &amp; memperbarui data surat ini di database -- nomor surat & berkas lama tidak dibuat baru,
                                cukup diperbarui.</p>
                        </form>
                    <?php elseif (!$kodeIdTerpilih): ?>
                        <p class="text-secondary text-xs mt-2 mb-0">Silakan pilih jenis surat &amp; template di atas
                            untuk mulai mengisi data surat.</p>
                    <?php endif; ?>
                </section>

                <section class="card-box">
                    <span class="text-xs fw-bold text-secondary text-uppercase d-block mb-2">Pratinjau Data ke Template
                        Word</span>
                    <?php if (!$kodeTerpilih || $file_template_hilang || $kodeTerpilih['format'] !== 'word_pdf'): ?>
                        <p class="text-secondary text-xs fst-italic mb-0">Pratinjau akan muncul di sini setelah jenis
                            surat &amp; template dipilih.</p>
                    <?php else: ?>
                        <p class="text-secondary text-xs fst-italic mb-3">
                            Panel ini menampilkan nilai yang akan menggantikan setiap placeholder <code>${...}</code>
                            saat dokumen digenerate. Klik "Update Preview" untuk menyegarkan tabel item (belum tersimpan sampai klik "Simpan Perubahan").
                            <?php if ($ada_ringkasan_total): ?>
                                Total, PPN, PPH &amp; Total Bayar adalah <b>estimasi langsung</b> dari isian tabel item.
                            <?php endif; ?>
                        </p>

                        <table class="preview-kv w-100 mb-3">
                                <tr>
                                    <td>Nomor</td>
                                    <td style="font-family:monospace;"><?= e($_POST['nomor_surat'] ?? $surat['nomor']) ?></td>
                                </tr>
                                <tr>
                                    <td>Perihal</td>
                                    <td><?= e(($_POST['perihal_manual'] ?? $surat['perihal'] ?? '') ?: '-') ?></td>
                                </tr>
                                <tr>
                                    <td>Tujuan</td>
                                    <td><?= e(($_POST['tujuan_manual'] ?? $surat['tujuan'] ?? '') ?: '-') ?></td>
                                </tr>
                                <?php foreach ($fields_dinamis as $f): ?>
                                <tr>
                                    <td><?= e($f['label']) ?></td>
                                    <td><?= nl2br(e($nilai_dinamis[$f['field']] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>

                        <?php if (!empty($fields_tabel)): ?>
                            <span class="text-xs fw-bold text-secondary text-uppercase d-block mb-2">Rincian Item</span>
                            <div class="table-responsive-custom mb-3">
                                <table class="table-custom" id="preview-tabel-item">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <?php foreach ($fields_tabel as $kolom): ?>
                                                <th><?= e($kolom['label']) ?></th>
                                            <?php endforeach; ?>
                                            <?php if ($tabel_item_punya_harga): ?>
                                                <th style="text-align:right;">Sub Total</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($nilai_items as $i => $baris): ?>
                                            <?php
                                            $adaIsiPreview = false;
                                            foreach ($baris as $v) {
                                                if (trim((string) $v) !== '') {
                                                    $adaIsiPreview = true;
                                                    break;
                                                }
                                            }
                                            if (!$adaIsiPreview)
                                                continue;

                                            $qtyBarisPreview = null;
                                            $hargaBarisPreview = null;
                                            foreach ($baris as $namaKolomPreview => $nilaiKolomPreview) {
                                                if ($qtyBarisPreview === null && isKolomQty((string) $namaKolomPreview)) {
                                                    $qtyBarisPreview = parseAngka($nilaiKolomPreview);
                                                }
                                                if ($hargaBarisPreview === null && isKolomHarga((string) $namaKolomPreview)) {
                                                    $hargaBarisPreview = parseAngka($nilaiKolomPreview);
                                                }
                                            }
                                            $subTotalBarisPreview = ($qtyBarisPreview !== null && $hargaBarisPreview !== null)
                                                ? ($qtyBarisPreview * $hargaBarisPreview)
                                                : null;
                                            ?>
                                            <tr data-baris-index="<?= (int) $i ?>">
                                                <td><?= $i + 1 ?></td>
                                                <?php foreach ($fields_tabel as $kolom): ?>
                                                    <td><?= e($baris[$kolom['field']] ?? '') ?></td>
                                                <?php endforeach; ?>
                                                <?php if ($tabel_item_punya_harga): ?>
                                                    <td style="text-align:right; font-family:monospace;">
                                                        <?= $subTotalBarisPreview !== null ? e(formatRupiah($subTotalBarisPreview)) : '-' ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($ada_ringkasan_total): ?>
                                <div id="blok-ringkasan-total" data-ada-pph23="<?= $ada_pph23 ? '1' : '0' ?>"
                                    data-ada-ppn="<?= $ada_ppn ? '1' : '0' ?>">
                                    <?php if ($ada_total): ?>
                                        <div class="ringkasan-total-row">
                                            <span>Total</span><span id="preview-total" style="font-family:monospace;">Rp. 0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_ppn): ?>
                                        <div class="ringkasan-total-row">
                                            <span>PPN (11%)</span><span id="preview-ppn" style="font-family:monospace;">Rp. 0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_pph23): ?>
                                        <div class="ringkasan-total-row">
                                            <span>PPH 23 (2%)</span><span id="preview-pph" style="font-family:monospace;">Rp. 0</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($ada_total_bayar): ?>
                                        <div class="ringkasan-total-row total-bayar">
                                            <span>Total Bayar</span><span id="preview-total-bayar" style="font-family:monospace;">Rp.
                                                0</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <script>
                                    (function () {
                                        var tbody = document.getElementById('tabel-item-body');
                                        var elTotal = document.getElementById('preview-total');
                                        var elPpn = document.getElementById('preview-ppn');
                                        var elTotalBayar = document.getElementById('preview-total-bayar');
                                        var previewTabelItem = document.getElementById('preview-tabel-item');
                                        if (!tbody || !elTotal) return;

                                        function parseAngkaJs(teks) {
                                            teks = String(teks || '').trim();
                                            var m = teks.match(/-?\d[\d.,]*/);
                                            if (!m) return null;
                                            var angka = m[0];
                                            if (angka.indexOf(',') !== -1 && angka.indexOf('.') !== -1) {
                                                angka = angka.replace(/\./g, '').replace(',', '.');
                                            } else if (angka.indexOf(',') !== -1) {
                                                angka = angka.replace(',', '.');
                                            } else {
                                                var bagian = angka.split('.');
                                                if (bagian.length > 1 && bagian[bagian.length - 1].length === 3) {
                                                    angka = angka.split('.').join('');
                                                }
                                            }
                                            var hasil = parseFloat(angka);
                                            return isNaN(hasil) ? null : hasil;
                                        }

                                        function formatRupiahJs(angka) {
                                            var bulat = Math.round(angka);
                                            var negatif = bulat < 0;
                                            bulat = Math.abs(bulat);
                                            var str = bulat.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                                            return (negatif ? '-' : '') + 'Rp. ' + str;
                                        }

                                        function updateSubtotalPreview(idxBaris, nilaiSubtotal) {
                                            if (!previewTabelItem) return;
                                            var barisPreview = previewTabelItem.querySelector('tr[data-baris-index="' + idxBaris + '"]');
                                            if (!barisPreview) return;
                                            var cellSubtotal = barisPreview.children[barisPreview.children.length - 1];
                                            if (!cellSubtotal) return;
                                            cellSubtotal.textContent = nilaiSubtotal !== null ? formatRupiahJs(nilaiSubtotal) : '-';
                                        }

                                        window.hitungUlangTotalSurat = function () {
                                            var totalSemuaBaris = 0;
                                            var adaSubtotal = false;

                                            tbody.querySelectorAll('tr.baris-item').forEach(function (tr) {
                                                var qty = null, harga = null;
                                                tr.querySelectorAll('input[data-kolom]').forEach(function (inp) {
                                                    var nama = inp.getAttribute('data-kolom');
                                                    if (qty === null && /qty|jumlah/i.test(nama)) qty = parseAngkaJs(inp.value);
                                                    if (harga === null && /harga/i.test(nama)) harga = parseAngkaJs(inp.value);
                                                });
                                                var idxBaris = tr.getAttribute('data-baris-index');
                                                if (qty !== null && harga !== null) {
                                                    totalSemuaBaris += qty * harga;
                                                    adaSubtotal = true;
                                                    updateSubtotalPreview(idxBaris, qty * harga);
                                                } else {
                                                    updateSubtotalPreview(idxBaris, null);
                                                }
                                            });

                                            var blokRingkasan = document.getElementById('blok-ringkasan-total');
                                            var adaPph23 = blokRingkasan && blokRingkasan.getAttribute('data-ada-pph23') === '1';
                                            var elPph = document.getElementById('preview-pph');

                                            if (!adaSubtotal) {
                                                elTotal.textContent = 'Rp. 0';
                                                elPpn.textContent = 'Rp. 0';
                                                if (elPph) elPph.textContent = 'Rp. 0';
                                                elTotalBayar.textContent = 'Rp. 0';
                                                return;
                                            }

                                            var ppn = Math.round(totalSemuaBaris * 0.11);
                                            var pph = adaPph23 ? Math.round(totalSemuaBaris * 0.02) : 0;
                                            var totalBayar = totalSemuaBaris + ppn - pph;

                                            elTotal.textContent = formatRupiahJs(totalSemuaBaris);
                                            elPpn.textContent = formatRupiahJs(ppn);
                                            if (adaPph23 && elPph) elPph.textContent = formatRupiahJs(pph);
                                            elTotalBayar.textContent = formatRupiahJs(totalBayar);
                                        };

                                        var observer = new MutationObserver(window.hitungUlangTotalSurat);
                                        observer.observe(tbody, { childList: true });

                                        window.hitungUlangTotalSurat();
                                    })();
                                </script>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
</main>

<script>
    // ==========================================
    // AUTOCOMPLETE NAMA PERUSAHAAN (Data_Klien)
    // Endpoint AJAX diarahkan ke surat.php (satu folder yang sama),
    // karena edit_surat.php tidak punya endpoint ajax=cari_klien sendiri.
    // ==========================================
    (function () {
        let timerCari = null;
        let boxAktif = null;
        let inputAktif = null;

        function tutupSaran() {
            if (boxAktif) {
                boxAktif.remove();
                boxAktif = null;
            }
            inputAktif = null;
        }

        function buatBoxSaran(input) {
            tutupSaran();
            const box = document.createElement('div');
            box.className = 'arp-autocomplete-box';
            box.style.cssText = `
            position:absolute; z-index:2000; background:#fff;
            border:1px solid var(--border-color,#e2e8f0); border-radius:8px;
            box-shadow:0 8px 20px rgba(0,0,0,0.12);
            max-height:220px; overflow-y:auto; font-size:0.85rem;
        `;
            document.body.appendChild(box);
            posisikanBox(box, input);
            boxAktif = box;
            inputAktif = input;
            return box;
        }

        function posisikanBox(box, input) {
            const rect = input.getBoundingClientRect();
            box.style.left = (rect.left + window.scrollX) + 'px';
            box.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            box.style.width = rect.width + 'px';
        }

        function tampilkanSaran(input, daftar) {
            const box = buatBoxSaran(input);
            if (!daftar.length) {
                box.innerHTML = '<div style="padding:10px 14px; color:var(--text-secondary,#64748b);">Tidak ada perusahaan cocok ditemukan.</div>';
                return;
            }
            daftar.forEach(function (klien) {
                const item = document.createElement('div');
                item.style.cssText = 'padding:10px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9;';
                item.innerHTML = '<div style="font-weight:600; color:var(--text-primary,#1e293b);">' +
                    escapeHtmlAC(klien.nama_perusahaan) + '</div>' +
                    (klien.pic_nama ? '<div style="color:var(--text-secondary,#64748b); font-size:0.78rem;">PIC: ' + escapeHtmlAC(klien.pic_nama) + '</div>' : '');
                item.addEventListener('mouseenter', function () { item.style.background = '#f8fafc'; });
                item.addEventListener('mouseleave', function () { item.style.background = '#fff'; });
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    clearTimeout(timerCari);

                    input.value = klien.nama_perusahaan;
                    input.dataset.acJustPicked = '1';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));

                    isiAlamatOtomatis(input, klien.alamat);

                    tutupSaran();
                    input.blur();
                });
                box.appendChild(item);
            });
        }

        function escapeHtmlAC(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function cariKlien(input) {
            if (input.dataset.acJustPicked === '1') {
                input.dataset.acJustPicked = '0';
                tutupSaran();
                return;
            }

            const q = input.value.trim();
            if (q.length < 1) {
                tutupSaran();
                return;
            }
            // PENTING: fetch ke surat.php (bukan edit_surat.php), karena
            // endpoint ajax=cari_klien ada di surat.php (satu folder admin/).
            fetch('surat.php?ajax=cari_klien&q=' + encodeURIComponent(q))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (document.activeElement === input) {
                        tampilkanSaran(input, data);
                    }
                })
                .catch(function () { tutupSaran(); });
        }

        function cariFieldAlamatTerkait(inputPerusahaan) {
            const wadah =
                inputPerusahaan.closest('tr.baris-item') ||
                inputPerusahaan.closest('.blok-baris') ||
                inputPerusahaan.closest('form') ||
                document;

            let kandidat = wadah.querySelectorAll('input[name^="dinamis["], textarea[name^="dinamis["]');
            let cocokKuat = null;
            let cocokLemah = null;

            kandidat.forEach(function (el) {
                const nama = (el.getAttribute('name') || '').toLowerCase();
                if (nama.indexOf('alamat') === -1) return;
                if (nama.indexOf('perusahaan') !== -1 && !cocokKuat) {
                    cocokKuat = el;
                } else if (!cocokLemah) {
                    cocokLemah = el;
                }
            });

            if (cocokKuat) return cocokKuat;
            if (cocokLemah) return cocokLemah;

            let cocokKolom = null;
            wadah.querySelectorAll('input[data-kolom], textarea[data-kolom]').forEach(function (el) {
                const nama = (el.getAttribute('data-kolom') || '').toLowerCase();
                if (nama.indexOf('alamat') !== -1 && !cocokKolom) {
                    cocokKolom = el;
                }
            });
            return cocokKolom;
        }

        function isiAlamatOtomatis(inputPerusahaan, alamat) {
            if (!alamat) return;
            const fieldAlamat = cariFieldAlamatTerkait(inputPerusahaan);
            if (!fieldAlamat) return;

            fieldAlamat.value = alamat;
            fieldAlamat.dispatchEvent(new Event('input', { bubbles: true }));
            fieldAlamat.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function pasangAutocomplete(input) {
            if (input.dataset.acTerpasang === '1') return;
            input.dataset.acTerpasang = '1';
            input.dataset.acJustPicked = '0';
            input.setAttribute('autocomplete', 'off');

            input.addEventListener('input', function () {
                clearTimeout(timerCari);
                timerCari = setTimeout(function () { cariKlien(input); }, 300);
            });
            input.addEventListener('focus', function () {
                if (input.dataset.acJustPicked === '1') return;
                if (input.value.trim().length >= 1) cariKlien(input);
            });
            input.addEventListener('blur', function () {
                setTimeout(tutupSaran, 150);
            });
            window.addEventListener('scroll', function () {
                if (inputAktif === input && boxAktif) posisikanBox(boxAktif, input);
            }, true);
        }

        function pasangKeSemuaFieldPerusahaan(root) {
            root = root || document;
            root.querySelectorAll('input[name^="dinamis["]').forEach(function (input) {
                const namaField = (input.getAttribute('name') || '').toLowerCase();
                if (namaField.indexOf('perusahaan') !== -1) {
                    pasangAutocomplete(input);
                }
            });
            root.querySelectorAll('input[data-kolom]').forEach(function (input) {
                const namaKolom = (input.getAttribute('data-kolom') || '').toLowerCase();
                if (namaKolom.indexOf('perusahaan') !== -1) {
                    pasangAutocomplete(input);
                }
            });
            // Field "Tujuan" manual di edit_surat.php
            root.querySelectorAll('input[name="tujuan_manual"]').forEach(pasangAutocomplete);
        }

        document.addEventListener('DOMContentLoaded', function () {
            pasangKeSemuaFieldPerusahaan(document);
        });

        const observerFieldBaru = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        pasangKeSemuaFieldPerusahaan(node);
                    }
                });
            });
        });
        document.addEventListener('DOMContentLoaded', function () {
            observerFieldBaru.observe(document.body, { childList: true, subtree: true });
        });
    })();
</script>

<?php include "../includes/footer.php"; ?>