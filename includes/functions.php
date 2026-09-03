<?php
// includes/functions.php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

// Define directory constants if not already defined
if (!defined('TEMPLATE_DIR')) {
    define('TEMPLATE_DIR', __DIR__ . '/../storage/templates/');
}
if (!defined('SURAT_KELUAR_DIR')) {
    define('SURAT_KELUAR_DIR', __DIR__ . '/../storage/surat_keluar/');
}
if (!defined('SURAT_MASUK_DIR')) {
    define('SURAT_MASUK_DIR', __DIR__ . '/../storage/surat_masuk/');
}


/**
 * Ambil salinan lokal SEMENTARA dari template yang tersimpan di Drive,
 * jalankan $callback($pathLokal, $mimeType), lalu file sementara otomatis
 * dihapus -- apa pun hasilnya (sukses/exception).
 *
 * @throws RuntimeException kalau gagal mengunduh dari Drive
 */
function arp_dengan_template_sementara(string $driveFileId, callable $callback)
{
    $unduhan = arp_unduh_dari_drive($driveFileId);
    if (!$unduhan) {
        throw new RuntimeException('Gagal mengambil template dari Google Drive: ' . arp_drive_last_error());
    }
    try {
        return $callback($unduhan['path'], $unduhan['mime_type']);
    } finally {
        if (is_file($unduhan['path'])) {
            @unlink($unduhan['path']);
        }
    }
}


// ==========================================
// RESOLVE NOMOR SURAT: bagian ANGKA URUT saja yang bisa diisi manual
// oleh user (mis. "015"); bagian kode jenis / "ARP" / bulan romawi /
// tahun tetap dibuat otomatis persis seperti generateNomorSurat().
// Kalau angka urut dikosongkan -> full otomatis (counter di
// Kode_Surat ikut naik). Kalau diisi manual -> divalidasi angka &
// dicek supaya tidak duplikat, TANPA mengubah counter kode_surat
// (jadi urutan otomatis berikutnya tidak "meloncat").
// ==========================================
function resolveNomorSurat(PDO $pdo, int $kode_id, ?string $noUrutManual = null): string
{
    $noUrutManual = trim((string) $noUrutManual);

    if ($noUrutManual === '') {
        return generateNomorSurat($pdo, $kode_id);
    }

    if (!ctype_digit($noUrutManual)) {
        throw new RuntimeException("Nomor urut surat harus berupa angka (contoh: 015).");
    }

    $stmt = $pdo->prepare("SELECT kode FROM Kode_Surat WHERE id = ?");
    $stmt->execute([$kode_id]);
    $kode = $stmt->fetchColumn();
    if (!$kode) {
        throw new RuntimeException("Kode surat tidak ditemukan.");
    }

    $tahun = (int) date('Y');
    $bulanRomawi = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][date('n') - 1];
    $nomorLengkap = sprintf('%03d/%s/ARP/%s/%d', (int) $noUrutManual, $kode, $bulanRomawi, $tahun);

    $cek = $pdo->prepare("SELECT id FROM Surat WHERE nomor = ? AND kode_id = ?");
    $cek->execute([$nomorLengkap, $kode_id]);
    if ($cek->fetch()) {
        throw new RuntimeException("Nomor surat \"{$nomorLengkap}\" sudah digunakan surat lain untuk jenis surat ini. Gunakan nomor urut lain atau kosongkan untuk otomatis.");
    }

    return $nomorLengkap;
}

// ==========================================
// GENERATE NOMOR SURAT OTOMATIS (per kode, reset tiap tahun)
// ==========================================
function generateNomorSurat(PDO $pdo, int $kode_id): string
{
    $tahun = (int) date('Y');

    $stmt = $pdo->prepare("SELECT kode, counter, tahun_counter FROM Kode_Surat WHERE id = ? FOR UPDATE");
    $stmt->execute([$kode_id]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException("Kode surat tidak ditemukan");
    }

    $counter = ((int) $row['tahun_counter'] === $tahun) ? $row['counter'] + 1 : 1;

    $update = $pdo->prepare("UPDATE Kode_Surat SET counter = ?, tahun_counter = ? WHERE id = ?");
    $update->execute([$counter, $tahun, $kode_id]);

    $bulanRomawi = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][date('n') - 1];

    return sprintf('%03d/%s/ARP/%s/%d', $counter, $row['kode'], $bulanRomawi, $tahun);
}

// ==========================================
// GENERATE NOMOR AGENDA OTOMATIS (arsip internal, terpisah untuk
// Surat Masuk & Surat Keluar, reset tiap tahun).
// Butuh tabel `agenda_counter` (lihat migrasi SQL):
//   CREATE TABLE agenda_counter (
//       id INT PRIMARY KEY AUTO_INCREMENT,
//       tahun INT NOT NULL,
//       arah ENUM('Masuk','Keluar') NOT NULL,
//       counter INT NOT NULL DEFAULT 0,
//       UNIQUE KEY uniq_tahun_arah (tahun, arah)
//   );
// Format hasil: "AGK-001/2026" (Keluar) atau "AGM-001/2026" (Masuk).
// ==========================================
function generateNomorAgenda(PDO $pdo, string $arah): string
{
    if (!in_array($arah, ['Masuk', 'Keluar'], true)) {
        throw new InvalidArgumentException("Arah surat tidak valid: {$arah}");
    }

    $tahun = (int) date('Y');

    // Kunci baris counter tahun ini (kalau sudah ada) supaya aman dari race condition
    // ketika dua surat disimpan hampir bersamaan.
    $stmt = $pdo->prepare("SELECT counter FROM agenda_counter WHERE tahun = ? AND arah = ? FOR UPDATE");
    $stmt->execute([$tahun, $arah]);
    $counterLama = $stmt->fetchColumn();

    if ($counterLama === false) {
        // Belum ada baris counter untuk tahun & arah ini -> buat baru mulai dari 1
        $counter = 1;
        try {
            $pdo->prepare("INSERT INTO agenda_counter (tahun, arah, counter) VALUES (?, ?, ?)")
                ->execute([$tahun, $arah, $counter]);
        } catch (\Throwable $e) {
            // Kemungkinan baris sudah dibuat oleh proses lain di antara SELECT & INSERT
            // (race condition) -> ambil ulang & increment lewat UPDATE di bawah.
            $stmtUlang = $pdo->prepare("SELECT counter FROM agenda_counter WHERE tahun = ? AND arah = ? FOR UPDATE");
            $stmtUlang->execute([$tahun, $arah]);
            $counter = (int) $stmtUlang->fetchColumn() + 1;
            $pdo->prepare("UPDATE agenda_counter SET counter = ? WHERE tahun = ? AND arah = ?")
                ->execute([$counter, $tahun, $arah]);
        }
    } else {
        $counter = (int) $counterLama + 1;
        $pdo->prepare("UPDATE agenda_counter SET counter = ? WHERE tahun = ? AND arah = ?")
            ->execute([$counter, $tahun, $arah]);
    }

    $prefix = $arah === 'Masuk' ? 'AGM' : 'AGK';

    return sprintf('%s-%03d/%d', $prefix, $counter, $tahun);
}

function formatTanggalIndonesia(string $tanggalYmd): string
{
    $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];
    $ts = strtotime($tanggalYmd);
    if (!$ts)
        return $tanggalYmd;

    return date('d', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

// ==========================================
// FORMAT ANGKA -> "Rp. 1.234.567"
// ==========================================
function formatRupiah($angka): string
{
    return 'Rp. ' . number_format((float) $angka, 0, ',', '.');
}

// ==========================================
// AMBIL ANGKA DARI TEKS BEBAS
// Dipakai supaya kolom Qty & Harga Satuan boleh diisi teks campuran seperti
// "3 unit", "5 orng", "6.055.000", "Rp 6.055.000", tapi tetap bisa dihitung.
// Contoh:
//   "3 unit"        -> 3
//   "5 orng"        -> 5
//   "6.055.000"     -> 6055000  (titik dianggap pemisah ribuan)
//   "6055000"       -> 6055000
//   "6055000,50"    -> 6055000.5 (koma dianggap desimal)
// Return null kalau tidak ada angka sama sekali di dalam teks.
// ==========================================
function parseAngka($teks): ?float
{
    $teks = trim((string) ($teks ?? ''));
    if ($teks === '' || !preg_match('/-?\d[\d.,]*/', $teks, $m)) {
        return null;
    }
    $angka = $m[0];

    if (strpos($angka, ',') !== false && strpos($angka, '.') !== false) {
        // Ada titik & koma sekaligus -> titik = pemisah ribuan, koma = desimal
        $angka = str_replace(',', '.', str_replace('.', '', $angka));
    } elseif (strpos($angka, ',') !== false) {
        // Hanya koma -> anggap sebagai desimal
        $angka = str_replace(',', '.', $angka);
    } else {
        // Hanya titik (atau tidak ada sama sekali) -> anggap pemisah ribuan
        // kalau polanya persis 3 digit di setiap kelompok belakang titik (cth 6.055.000)
        $bagian = explode('.', $angka);
        if (count($bagian) > 1 && strlen(end($bagian)) === 3) {
            $angka = str_replace('.', '', $angka);
        }
    }

    return is_numeric($angka) ? (float) $angka : null;
}

// ==========================================
// AMBIL TEKS POLOS (TANPA TAG XML) DARI SATU FILE .docx
// Dipakai untuk mendeteksi penulisan ASLI sebuah placeholder ${...} di
// dalam dokumen (termasuk huruf besar/kecilnya persis seperti diketik
// penulis template), supaya field otomatis sistem (ppn, pph_23, total,
// total_bayar, dst -- yang di kode ini namanya HARDCODE huruf kecil) tetap
// bisa terisi walau di template ditulis ${PPN}, ${Total}, dst.
// ==========================================
function ambilTeksPolosDocx(string $fullPath): string
{
    if (!file_exists($fullPath)) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($fullPath) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return '';
    }
    $plain = preg_replace('/<[^>]+>/', '', $xml);
    return html_entity_decode($plain, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// Cari nama-persis (huruf besar/kecil ASLI seperti ditulis di template)
// dari sebuah placeholder ${namaField} atau {namaField}. Kalau tidak
// ditemukan sama sekali (beda huruf besar/kecil ATAU memang tidak ada di
// template), kembalikan null supaya pemanggil bisa jatuh kembali ke nama
// aslinya (aman, tidak mengubah perilaku lama).
function cariNamaMacroAsli(string $teksPolosDokumen, string $namaField): ?string
{
    if ($teksPolosDokumen === '') {
        return null;
    }
    $namaFieldQuoted = preg_quote($namaField, '/');
    if (
        preg_match('/\$\{\s*(' . $namaFieldQuoted . ')\s*\}/i', $teksPolosDokumen, $m)
        || preg_match('/(?<!\$)\{\s*(' . $namaFieldQuoted . ')\s*\}/i', $teksPolosDokumen, $m)
    ) {
        return $m[1];
    }
    return null;
}

// ==========================================
// AMBIL SATUAN (TEKS SETELAH ANGKA) DARI TEKS BEBAS
// Dipakai untuk kolom kuantitas non-uang seperti "Jumlah Alat" (cth "1 Unit",
// "3 orang", "5 pcs"), supaya total gabungan bisa ditulis dengan satuan yang
// sama, cth "13 Unit".
// Contoh:
//   "1 Unit"   -> "Unit"
//   "3 orang"  -> "orang"
//   "5"        -> null (tidak ada satuan)
// Return null kalau tidak ada angka atau tidak ada teks tersisa setelah angka.
// ==========================================
function ambilSatuanTeks($teks): ?string
{
    $teks = trim((string) ($teks ?? ''));
    if ($teks === '' || !preg_match('/-?\d[\d.,]*/', $teks, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $akhirAngka = $m[0][1] + strlen($m[0][0]);
    $sisa = trim(substr($teks, $akhirAngka));
    $sisa = trim($sisa, " \t\n\r\0\x0B-:");

    return $sisa !== '' ? $sisa : null;
}

// ==========================================
// TERBILANG: ubah angka jadi teks bahasa Indonesia
// Dipakai untuk mengisi ${terbilang} (cth: "Tiga Puluh Dua Juta ... Rupiah")
// ==========================================
function terbilang(float $angka): string
{
    $angka = (int) round($angka);
    if ($angka < 0) {
        return 'Minus ' . terbilang(-$angka);
    }

    $huruf = [
        '',
        'Satu',
        'Dua',
        'Tiga',
        'Empat',
        'Lima',
        'Enam',
        'Tujuh',
        'Delapan',
        'Sembilan',
        'Sepuluh',
        'Sebelas'
    ];

    if ($angka < 12) {
        return $huruf[$angka];
    } elseif ($angka < 20) {
        return terbilang($angka - 10) . ' Belas';
    } elseif ($angka < 100) {
        return trim(terbilang(intdiv($angka, 10)) . ' Puluh ' . terbilang($angka % 10));
    } elseif ($angka < 200) {
        return trim('Seratus ' . terbilang($angka - 100));
    } elseif ($angka < 1000) {
        return trim(terbilang(intdiv($angka, 100)) . ' Ratus ' . terbilang($angka % 100));
    } elseif ($angka < 2000) {
        return trim('Seribu ' . terbilang($angka - 1000));
    } elseif ($angka < 1000000) {
        return trim(terbilang(intdiv($angka, 1000)) . ' Ribu ' . terbilang($angka % 1000));
    } elseif ($angka < 1000000000) {
        return trim(terbilang(intdiv($angka, 1000000)) . ' Juta ' . terbilang($angka % 1000000));
    } elseif ($angka < 1000000000000) {
        return trim(terbilang(intdiv($angka, 1000000000)) . ' Miliar ' . terbilang($angka % 1000000000));
    }

    return trim(terbilang(intdiv($angka, 1000000000000)) . ' Triliun ' . terbilang($angka % 1000000000000));
}

// ==========================================
// PREFIX KHUSUS UNTUK KOLOM TABEL BERULANG
// Placeholder di Word yang diawali "item_" (cth ${item_deskripsi}) dianggap
// sebagai satu baris tabel yang akan di-clone sesuai jumlah baris yang diisi
// user, BUKAN field tunggal biasa.
// ==========================================
const PREFIX_KOLOM_TABEL = 'item_';
const ANCHOR_KOLOM_TABEL = 'item_no';       // nomor urut baris, diisi otomatis
const KOLOM_SUBTOTAL = 'item_sub_total';    // subtotal per baris, dihitung otomatis

// Field hasil hitungan otomatis sistem (TIDAK boleh jadi input form manual,
// supaya user tidak bisa override / salah ketik dan supaya nilainya
// selalu konsisten format "Rp. 1.234.567").
// - total/ppn/pph_23/total_bayar/terbilang : untuk tabel item bertipe uang (qty x harga)
// - total_alat : untuk tabel item bertipe kuantitas saja (tanpa harga), cth "13 Unit"
const FIELD_OTOMATIS_SISTEM = ['nomor', 'nomor_surat', 'no_surat', 'total', 'ppn', 'pph_23', 'diskon', 'diskon_persen', 'total_bayar', 'terbilang', 'total_alat', 'grand_total', 'down_payment', 'down_payment_persen', 'sisa_pelunasan', 'ttd_direksi'];

const PREFIX_INVOICE = 'invoice_';

function mapFieldInvoiceKeTemplate(array $dataInvoice): array
{
    return [
        'invoice_nomor' => $dataInvoice['nomor_invoice'] ?? '-',
        'invoice_tanggal' => !empty($dataInvoice['tanggal_invoice'])
            ? formatTanggalIndonesia($dataInvoice['tanggal_invoice'])
            : '-',
        'invoice_perihal' => $dataInvoice['perihal_invoice'] ?? '-',
        'invoice_nama_perusahaan' => $dataInvoice['nama_perusahaan'] ?? '-',
        'invoice_item_deskripsi' => $dataInvoice['item_deskripsi'] ?? '-',
        'invoice_nomor_pesanan' => $dataInvoice['nomor_pesanan'] ?? '-',
        'invoice_grand_total' => $dataInvoice['grand_total_format'] ?? formatRupiah(0),
        'invoice_total_bayar' => $dataInvoice['total_bayar_format'] ?? formatRupiah(0),
        'invoice_terbilang' => $dataInvoice['terbilang'] ?? (terbilang(0) . ' Rupiah'),
    ];
}

// ==========================================
// PENANDA BLOK LIST BERULANG (BUKAN tabel Word), cth di dalam .docx:
// ${blok_pemeriksa}
// Nama: ${pemeriksa_nama} - Jabatan: ${pemeriksa_jabatan}
// ${/blok_pemeriksa}
// Dipakai untuk daftar bernomor / list yang bisa ditambah baris bebas lewat
// tombol "+ Tambah" di form, tapi TIDAK berbentuk tabel Word (beda mekanisme
// dari item_... + cloneRow -- blok ini pakai cloneBlock()).
// ==========================================
const PREFIX_BLOK = 'blok_';


// ==========================================
// HITUNG RINGKASAN TOTAL (Total, Diskon, PPN, PPH23, Grand Total, DP,
// Total Bayar, Sisa Pelunasan, Terbilang) DARI SATU SET ITEMS.
// Rumus sama persis dengan yang dipakai generateSuratDocx(), tapi
// dibungkus jadi fungsi terpisah supaya bisa dipakai ULANG di luar proses
// generate docx -- misalnya untuk menghitung ulang Grand Total & Terbilang
// sebuah INVOICE yang sudah tersimpan (dipakai saat membuat Kuitansi
// otomatis dari invoice tsb).
// ==========================================
function hitungRingkasanTotalSurat(array $items, array $ringkasanDisertakan = [], $diskonPersenInput = 0, $dpPersenInput = 0): array
{
    $sertakanPpn = $ringkasanDisertakan['ppn'] ?? true;
    $sertakanPph23 = $ringkasanDisertakan['pph_23'] ?? true;
    $sertakanDiskon = $ringkasanDisertakan['diskon'] ?? true;
    $sertakanDp = $ringkasanDisertakan['dp'] ?? true;
    $sertakanSisaPelunasan = $ringkasanDisertakan['sisa_pelunasan'] ?? true;

    $totalSemuaBaris = 0.0;
    $adaSubtotalOtomatis = false;

    foreach ($items as $item) {
        $qty = null;
        $harga = null;
        $adaKolomQty = false;
        foreach ($item as $namaKolom => $nilai) {
            if (preg_match('/qty|jumlah/i', (string) $namaKolom)) {
                $adaKolomQty = true;
                if ($qty === null) {
                    $qty = parseAngka($nilai);
                }
            }
            if ($harga === null && preg_match('/harga/i', (string) $namaKolom)) {
                $harga = parseAngka($nilai);
            }
        }
        $subTotalBaris = null;
        if ($harga !== null) {
            if ($qty !== null) {
                $subTotalBaris = $qty * $harga;
            } elseif (!$adaKolomQty) {
                $subTotalBaris = $harga;
            }
        }
        if ($subTotalBaris !== null) {
            $totalSemuaBaris += $subTotalBaris;
            $adaSubtotalOtomatis = true;
        }
    }

    $hasil = [
        'ada_subtotal' => $adaSubtotalOtomatis,
        'total' => 0.0,
        'diskon_persen' => 0.0,
        'diskon' => 0.0,
        'ppn' => 0.0,
        'pph_23' => 0.0,
        'grand_total' => 0.0,
        'down_payment_persen' => 0.0,
        'down_payment' => 0.0,
        'total_bayar' => 0.0,
        'sisa_pelunasan' => 0.0,
    ];

    if (!$adaSubtotalOtomatis) {
        return $hasil;
    }

    $hasil['total'] = $totalSemuaBaris;

    $diskonPersen = $sertakanDiskon ? (float) $diskonPersenInput : 0.0;
    $diskonNominal = $sertakanDiskon ? round($totalSemuaBaris * ($diskonPersen / 100)) : 0.0;
    $hasil['diskon_persen'] = $diskonPersen;
    $hasil['diskon'] = $diskonNominal;

    $dasarPajak = ($sertakanDiskon && $diskonNominal > 0) ? ($totalSemuaBaris - $diskonNominal) : $totalSemuaBaris;

    $ppn = $sertakanPpn ? round($dasarPajak * 0.11) : 0;
    $pph = $sertakanPph23 ? round($dasarPajak * 0.02) : 0;
    $hasil['ppn'] = $ppn;
    $hasil['pph_23'] = $pph;

    $grandTotal = $totalSemuaBaris + $ppn - $pph - $diskonNominal;
    $hasil['grand_total'] = $grandTotal;

    $dpPersen = $sertakanDp ? (float) $dpPersenInput : 0.0;
    $dpNominal = $sertakanDp ? round($grandTotal * ($dpPersen / 100)) : 0.0;
    $hasil['down_payment_persen'] = $dpPersen;
    $hasil['down_payment'] = $dpNominal;

    $totalBayar = ($sertakanDp && $dpNominal > 0) ? ($grandTotal - $dpNominal) : $grandTotal;
    $hasil['total_bayar'] = $totalBayar;

    $sisaPelunasan = $grandTotal - $dpNominal;
    $hasil['sisa_pelunasan'] = $sertakanSisaPelunasan ? $sisaPelunasan : $sisaPelunasan;

    return $hasil;
}

// ==========================================
// AMBIL DATA DARI SATU SURAT INVOICE (yang SUDAH tersimpan di tabel surat)
// UNTUK DIPAKAI SEBAGAI SUMBER OTOMATIS SAAT MEMBUAT KUITANSI.
// Tidak menyentuh berkas Word invoice sama sekali -- murni membaca ulang
// isi_data (JSON) yang tersimpan saat invoice itu dibuat/diedit, lalu
// menghitung ulang Grand Total & Terbilang-nya (nilai hasil hitung TIDAK
// disimpan mentah di isi_data, hanya input mentahnya saja yang disimpan).
// ==========================================
function muatDataInvoiceUntukKuitansi(PDO $pdo, int $invoiceSuratId): ?array
{
    $stmt = $pdo->prepare("
        SELECT s.*, k.nama AS jenis_surat_nama
        FROM Surat s
        JOIN Kode_Surat k ON k.id = s.kode_id
        WHERE s.id = ? AND s.arah = 'Keluar'
    ");
    $stmt->execute([$invoiceSuratId]);
    $invoice = $stmt->fetch();
    if (!$invoice) {
        return null;
    }

    $isiData = json_decode($invoice['isi_data'] ?? '', true) ?: [];
    $items = $isiData['__items'] ?? [];
    $ringkasan = $isiData['__ringkasan'] ?? [];
    $diskonPersenInput = parseAngka($isiData['diskon_input'] ?? '0') ?? 0.0;
    $dpPersenInput = parseAngka($isiData['dp_input'] ?? '0') ?? 0.0;

    $hitung = hitungRingkasanTotalSurat($items, $ringkasan, $diskonPersenInput, $dpPersenInput);

    // ==========================================
    // TENTUKAN NILAI FINAL UNTUK KUITANSI, mengikuti centangan di
    // invoice SUMBER-nya (bukan checkbox kuitansi, karena form Kuitansi
    // memang tidak menampilkan checkbox ini):
    //  - "Sertakan Total Bayar" dicentang -> pakai Total Bayar
    //  - kalau tidak, tapi "Sertakan Grand Total" dicentang -> pakai Grand Total
    //  - kalau KEDUANYA dicentang -> FOKUS ke Total Bayar
    //  - kalau tidak ada satupun -> fallback ke Grand Total
    // Nilai ini dipakai untuk MENGISI ${grand_total} MAUPUN ${total_bayar}
    // di template Kuitansi, jadi berapa pun nama placeholder yang dipakai
    // template, hasilnya tetap benar & konsisten.
    // ==========================================
    $sertakanTotalBayarInvoice = $ringkasan['total_bayar'] ?? true;
    $sertakanGrandTotalInvoice = $ringkasan['grand_total'] ?? true;

    $nilaiFinal = $sertakanTotalBayarInvoice
        ? $hitung['total_bayar']
        : ($sertakanGrandTotalInvoice ? $hitung['grand_total'] : $hitung['grand_total']);

    // Nama perusahaan: coba beberapa nama field yang lazim dipakai template
    // invoice, fallback ke kolom 'tujuan' pada baris surat invoice-nya.
    $namaPerusahaan = '-';
    foreach (['nama_perusahaan', 'nama_perusahaan_tujuan', 'instansi_tujuan', 'tujuan'] as $kandidat) {
        if (!empty($isiData[$kandidat])) {
            $namaPerusahaan = $isiData[$kandidat];
            break;
        }
    }
    if ($namaPerusahaan === '-' && !empty($invoice['tujuan'])) {
        $namaPerusahaan = $invoice['tujuan'];
    }

    $deskripsiList = [];
    foreach ($items as $item) {
        $nilaiDeskripsi = null;
        foreach ($item as $namaKolom => $nilai) {
            if (preg_match('/deskripsi|uraian|nama/i', (string) $namaKolom)) {
                $nilaiDeskripsi = $nilai;
                break;
            }
        }
        if ($nilaiDeskripsi === null && !empty($item)) {
            $nilaiDeskripsi = reset($item);
        }
        $nilaiDeskripsi = trim((string) $nilaiDeskripsi);
        if ($nilaiDeskripsi !== '') {
            $deskripsiList[] = $nilaiDeskripsi;
        }
    }
    $itemDeskripsiGabungan = count($deskripsiList) > 1
        ? implode(', ', $deskripsiList)
        : ($deskripsiList[0] ?? ($invoice['perihal'] ?? '-'));

    $nomorPesanan = '-';
    foreach ($isiData as $namaField => $nilai) {
        if (preg_match('/pesanan/i', (string) $namaField) && trim((string) $nilai) !== '') {
            $nomorPesanan = $nilai;
            break;
        }
    }

    return [
        'invoice_id' => (int) $invoice['id'],
        'nomor_invoice' => $invoice['nomor'],
        'perihal_invoice' => $invoice['perihal'],
        'tanggal_invoice' => $invoice['tgl_dibuat'],
        'nama_perusahaan' => $namaPerusahaan,
        'item_deskripsi' => $itemDeskripsiGabungan,
        'nomor_pesanan' => $nomorPesanan,
        'grand_total' => $nilaiFinal,
        'grand_total_format' => formatRupiah($nilaiFinal),
        'total_bayar_format' => formatRupiah($nilaiFinal),               // ⬅ BARU
        'sumber_nilai' => $sertakanTotalBayarInvoice ? 'total_bayar' : 'grand_total', // ⬅ BARU (opsional, buat info di UI)
        'terbilang' => terbilang($nilaiFinal) . ' Rupiah',
        'ada_subtotal' => $hitung['ada_subtotal'],
    ];
}

// ==========================================
// DAFTAR SURAT INVOICE (surat keluar dengan jenis surat mengandung kata
// "Invoice") -- dipakai untuk dropdown "Pilih Invoice Sumber" saat
// membuat Kuitansi.
// ==========================================
function daftarSuratInvoice(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT s.id, s.nomor, s.perihal, s.tujuan, s.tgl_dibuat, s.status
        FROM Surat s
        JOIN Kode_Surat k ON k.id = s.kode_id
        WHERE s.arah = 'Keluar' AND k.nama LIKE '%Invoice%'
        ORDER BY s.tgl_dibuat DESC, s.id DESC
    ");
    return $stmt->fetchAll();
}


// ==========================================
// GENERATE FILE SURAT (.docx) DARI TEMPLATE MASTER
// Mendukung 2 gaya placeholder: ${...} dan {...} (lihat replaceBraceOnlyPlaceholders),
// SERTA tabel item berulang lewat cloneRow (lihat scanPlaceholdersFromDocx).
//
// $dataForm : field biasa hasil isian user, cth ['ditujukan_kepada' => 'PT ABC', ...]
// $items    : baris-baris tabel, cth [
//                 ['deskripsi' => 'Teknisi Gas Detector Training', 'qty' => '3 orang', 'harga_satuan' => '6.055.000'],
//                 ['deskripsi' => 'Teknisi Confined Space Training', 'qty' => '2 orang', 'harga_satuan' => '6055000'],
//             ]
//             (key di tiap baris = nama placeholder tabel TANPA prefix "item_")
//             Kolom qty & harga boleh berisi teks campuran (cth "3 unit"),
//             sistem tetap bisa menghitung karena memakai parseAngka().
//
//             Kalau tabel TIDAK punya kolom harga sama sekali (cth tabel daftar
//             alat: hanya nama alat + jumlah alat), sistem tidak menghitung
//             total/ppn/pph_23/total_bayar/terbilang (karena memang butuh
//             harga), tapi tetap menghitung ${total_alat} = jumlah semua
//             kolom kuantitas (qty-like) digabung, cth "13 Unit".
// ==========================================
function generateSuratDocx(string $templatePath, array $dataForm, array $items, string $nomorSurat, array $blocks = [], string $jenisSurat = '', ?string $tujuanManual = null, array $ringkasanDisertakan = [], int $revisiKe = 0): string
{
    if (!file_exists($templatePath)) {
        throw new RuntimeException("File template master tidak ditemukan: {$templatePath}");
    }

    $sertakanPpn = $ringkasanDisertakan['ppn'] ?? true;
    $sertakanPph23 = $ringkasanDisertakan['pph_23'] ?? true;
    $sertakanDiskon = $ringkasanDisertakan['diskon'] ?? true;
    $sertakanGrandTotal = $ringkasanDisertakan['grand_total'] ?? true;
    $sertakanDp = $ringkasanDisertakan['dp'] ?? true;
    $sertakanTotalBayar = $ringkasanDisertakan['total_bayar'] ?? true;
    $sertakanSisaPelunasan = $ringkasanDisertakan['sisa_pelunasan'] ?? true;   // ⬅ BARU

    $processor = new TemplateProcessor($templatePath);

    // Teks polos dokumen ASLI (huruf besar/kecil apa adanya), dipakai supaya
    // field otomatis sistem (ppn, pph_23, total, total_bayar, dst) tetap
    // terisi walau di template ditulis dengan huruf besar/kecil yang beda
    // dari nama field yang dipakai kode ini (lihat cariNamaMacroAsli()).
    $teksPolosTemplateAsli = ambilTeksPolosDocx($templatePath);

    // -----------------------------------------------------
    // 1) TABEL ITEM (kalau template punya placeholder item_...)
    //    - anchor untuk cloneRow() dibuat DINAMIS: coba item_no dulu
    //      (kalau template punya kolom nomor urut), kalau tidak ada
    //      fallback ke kolom item_... pertama yang memang diisi user.
    //    - NOMOR YANG DITULIS ke ${item_no} sekarang dihitung PER GRUP
    //      PERUSAHAAN (kalau ada kolom mengandung "perusahaan"): baris
    //      berurutan dengan perusahaan sama dianggap 1 nomor yang sama,
    //      baru lanjut ke nomor berikutnya kalau perusahaannya beda.
    //      Ini supaya nomornya konsisten dengan hasil merge sel vertikal
    //      di Tahap 3 (cth: 1, 1, 2 -- bukan 1, 2, 3 yang bikin nomor
    //      "loncat" ke 3 setelah baris ke-2 digabung/disembunyikan).
    //      Kalau tidak ada kolom perusahaan, nomor tetap urut biasa (1,2,3,...).
    //    - hitung sub_total per baris (qty x harga) kalau kolomnya ada
    //    - kolom harga_satuan ditulis ulang dalam format "Rp. 1.234.567"
    //    - hitung total, ppn, pph_23, total_bayar, terbilang kalau
    //      template punya placeholder itu (tergabung ke $dataForm)
    //    - kalau tidak ada kolom harga sama sekali, hitung total_alat
    // -----------------------------------------------------
    $totalSemuaBaris = 0.0;
    $adaSubtotalOtomatis = false;

    $totalKuantitas = 0.0;
    $adaKuantitasOtomatis = false;
    $satuanKuantitas = null;

    if (!empty($items)) {
        $jumlahBaris = count($items);

        $kolomPertamaItems = array_key_first((array) reset($items));
        $kandidatAnchor = array_values(array_unique(array_filter([
            ANCHOR_KOLOM_TABEL,
            $kolomPertamaItems !== null ? PREFIX_KOLOM_TABEL . $kolomPertamaItems : null,
        ])));

        $jumlahTabelDiproses = 0;
        while (true) {
            $anchorDipakai = null;
            foreach ($kandidatAnchor as $kandidat) {
                try {
                    $processor->cloneRow($kandidat, $jumlahBaris);
                    $anchorDipakai = $kandidat;
                    break;
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if ($anchorDipakai === null) {
                break;
            }

            $jumlahTabelDiproses++;
            if ($jumlahTabelDiproses >= 20) {
                break;
            }
        }

        if ($jumlahTabelDiproses > 0) {

            // Deteksi kolom "perusahaan" (kalau ada) supaya nomor urut yang
            // ditulis ke ${item_no} mengikuti GRUP perusahaan, konsisten
            // dengan merge sel vertikal di Tahap 3.
            $kolomPerusahaanUntukNomor = null;
            foreach ((array) reset($items) as $namaKolom => $_) {
                if (preg_match('/perusahaan/i', $namaKolom)) {
                    $kolomPerusahaanUntukNomor = $namaKolom;
                    break;
                }
            }
            $nomorUrutTampil = 0;
            $nilaiPerusahaanSebelumnya = null;

            foreach (array_values($items) as $i => $item) {

                $baris = $i + 1; // index fisik baris hasil clone -- JANGAN diubah,
                // ini dipakai PhpWord untuk macro item_xxx#N

                // Nomor yang DITULIS ke ${item_no} bisa beda dari $baris kalau
                // ada kolom perusahaan: baris dengan perusahaan sama
                // (berurutan) dapat nomor yang sama, baru naik nomor kalau
                // perusahaannya beda dari baris sebelumnya.
                if ($kolomPerusahaanUntukNomor !== null) {
                    $nilaiPerusahaanSekarang = trim((string) ($item[$kolomPerusahaanUntukNomor] ?? ''));
                    if ($nilaiPerusahaanSekarang === '' || $nilaiPerusahaanSekarang !== $nilaiPerusahaanSebelumnya) {
                        $nomorUrutTampil++;
                    }
                    $nilaiPerusahaanSebelumnya = $nilaiPerusahaanSekarang;
                } else {
                    $nomorUrutTampil = $baris;
                }

                $qty = null;
                $harga = null;
                $qtyTeksMentah = null;
                $adaKolomQtyDiTabelIni = false;
                foreach ($item as $namaKolom => $nilai) {
                    if (preg_match('/qty|jumlah/i', $namaKolom)) {
                        $adaKolomQtyDiTabelIni = true;
                        if ($qty === null) {
                            $qty = parseAngka($nilai);
                            $qtyTeksMentah = $nilai;
                        }
                    }
                    if ($harga === null && preg_match('/harga/i', $namaKolom)) {
                        $harga = parseAngka($nilai);
                    }
                }

                if ($qty !== null) {
                    $totalKuantitas += $qty;
                    $adaKuantitasOtomatis = true;
                    if ($satuanKuantitas === null) {
                        $satuanKuantitas = ambilSatuanTeks($qtyTeksMentah);
                    }
                }

                $subTotalBaris = null;
                if ($harga !== null) {
                    if ($qty !== null) {
                        $subTotalBaris = $qty * $harga;
                    } elseif (!$adaKolomQtyDiTabelIni) {
                        $subTotalBaris = $harga;
                    }
                }
                if ($subTotalBaris !== null) {
                    $totalSemuaBaris += $subTotalBaris;
                    $adaSubtotalOtomatis = true;
                }

                // Nomor urut baris HANYA diisi kalau template memang punya
                // placeholder ${item_no}; kalau tidak ada, otomatis dilewati.
                try {
                    $processor->setValue(ANCHOR_KOLOM_TABEL . '#' . $baris, $nomorUrutTampil);
                } catch (\Throwable $e) {
                    // template tidak punya item_no, lewati
                }

                foreach ($item as $namaKolom => $nilai) {
                    $macro = PREFIX_KOLOM_TABEL . $namaKolom . '#' . $baris;
                    $nilaiTampil = $nilai;

                    if (preg_match('/harga/i', $namaKolom)) {
                        $angkaHarga = parseAngka($nilai);
                        if ($angkaHarga !== null) {
                            $nilaiTampil = formatRupiah($angkaHarga);
                        }
                    }

                    try {
                        $processor->setValue($macro, htmlspecialchars((string) $nilaiTampil, ENT_QUOTES));
                    } catch (\Throwable $e) {
                        // kolom ini tidak ada di template, lewati
                    }
                }

                if ($subTotalBaris !== null) {
                    try {
                        $processor->setValue(KOLOM_SUBTOTAL . '#' . $baris, formatRupiah($subTotalBaris));
                    } catch (\Throwable $e) {
                        // template tidak punya placeholder sub_total, lewati
                    }
                }
            }

            if ($adaSubtotalOtomatis) {
                try {
                    $processor->setValue(KOLOM_SUBTOTAL, formatRupiah($totalSemuaBaris));
                } catch (\Throwable $e) {
                    // template tidak punya placeholder statis item_sub_total, lewati
                }
            }
        }
    }

    // -----------------------------------------------------
    // 1b) BLOK LIST BERULANG (${blok_nama} ... ${/blok_nama})
    // -----------------------------------------------------
    foreach ($blocks as $namaBlok => $barisBlok) {
        $barisBlok = array_values(array_filter((array) $barisBlok, function ($baris) {
            foreach ((array) $baris as $v) {
                if (trim((string) $v) !== '')
                    return true;
            }
            return false;
        }));

        if (empty($barisBlok)) {
            continue;
        }

        $fieldKunci = null;
        $fieldTanggal = null;
        foreach ((array) reset($barisBlok) as $namaField => $_) {
            if ($fieldKunci === null && preg_match('/^nama/i', $namaField)) {
                $fieldKunci = $namaField;
            }
            if ($fieldTanggal === null && preg_match('/tanggal/i', $namaField)) {
                $fieldTanggal = $namaField;
            }
        }

        if ($fieldKunci !== null && $fieldTanggal !== null) {
            $dikelompokkan = [];
            foreach ($barisBlok as $baris) {
                $kunci = trim((string) ($baris[$fieldKunci] ?? ''));
                if (!isset($dikelompokkan[$kunci])) {
                    $dikelompokkan[$kunci] = $baris;
                    $dikelompokkan[$kunci][$fieldTanggal] = [];
                }
                $nilaiTanggal = trim((string) ($baris[$fieldTanggal] ?? ''));
                if ($nilaiTanggal !== '') {
                    $dikelompokkan[$kunci][$fieldTanggal][] = $nilaiTanggal;
                }
            }

            $barisBlokBaru = [];
            foreach ($dikelompokkan as $baris) {
                $daftarTanggal = $baris[$fieldTanggal];
                $baris[$fieldTanggal] = count($daftarTanggal) > 1
                    ? implode("\n", array_map(fn($t) => '- ' . $t, $daftarTanggal))
                    : ($daftarTanggal[0] ?? '');
                $barisBlokBaru[] = $baris;
            }
            $barisBlok = $barisBlokBaru;
        }

        try {
            $processor->cloneBlock(PREFIX_BLOK . $namaBlok, count($barisBlok), true, true);

            foreach (array_values($barisBlok) as $i => $baris) {
                $idx = $i + 1;

                try {
                    $processor->setValue('no#' . $idx, htmlspecialchars((string) $idx, ENT_QUOTES));
                } catch (\Throwable $e) {
                }

                foreach ((array) $baris as $namaField => $nilai) {
                    try {
                        if ($namaField === $fieldTanggal && strpos((string) $nilai, "\n") !== false) {
                            $nilaiXml = implode('<w:br/>', array_map(
                                fn($baris) => htmlspecialchars($baris, ENT_QUOTES),
                                explode("\n", $nilai)
                            ));
                            $processor->setValue($namaField . '#' . $idx, $nilaiXml);
                        } else {
                            $processor->setValue($namaField . '#' . $idx, htmlspecialchars((string) $nilai, ENT_QUOTES));
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    // -----------------------------------------------------
    // 2) HITUNG OTOMATIS: total, ppn, pph_23, diskon, total_bayar, terbilang
    // -----------------------------------------------------
    if ($adaSubtotalOtomatis) {
        $dataForm['total'] = formatRupiah($totalSemuaBaris);

        // ----- DISKON: sekarang diinput sebagai PERSEN (cth "2" = 2%), bukan
        // nominal Rp langsung. Nominalnya = persen x Total.
        $diskonPersen = $sertakanDiskon ? (parseAngka($dataForm['diskon_input'] ?? '0') ?? 0.0) : 0.0;
        unset($dataForm['diskon_input']);
        $diskonNominal = $sertakanDiskon ? round($totalSemuaBaris * ($diskonPersen / 100)) : 0.0;
        if ($sertakanDiskon) {
            $dataForm['diskon'] = formatRupiah($diskonNominal);
            // Placeholder tambahan untuk menampilkan angka persennya, cth
            // template bisa menulis "DISKON ${diskon_persen}" -> "DISKON 2%"
            $diskonPersenTampil = (floor($diskonPersen) == $diskonPersen)
                ? (string) (int) $diskonPersen
                : rtrim(rtrim(number_format($diskonPersen, 2, ',', '.'), '0'), ',');
            $dataForm['diskon_persen'] = $diskonPersenTampil . '%';
        }

        // ----- DASAR PENGENAAN PAJAK (DPP): Total dikurangi Diskon (kalau ada
        // diskon yang disertakan & nilainya > 0). Kalau tidak ada diskon, basis
        // hitung PPN/PPH tetap Total penuh (perilaku lama).
        $dasarPajak = ($sertakanDiskon && $diskonNominal > 0)
            ? ($totalSemuaBaris - $diskonNominal)
            : $totalSemuaBaris;

        $ppn = $sertakanPpn ? round($dasarPajak * 0.11) : 0;
        if ($sertakanPpn) {
            $dataForm['ppn'] = formatRupiah($ppn);
        }

        $pph = $sertakanPph23 ? round($dasarPajak * 0.02) : 0;
        if ($sertakanPph23) {
            $dataForm['pph_23'] = formatRupiah($pph);
        }

        // ----- BARU -----
        $grandTotal = $totalSemuaBaris + $ppn - $pph - $diskonNominal;
        if ($sertakanGrandTotal) {
            $dataForm['grand_total'] = formatRupiah($grandTotal);
        }

        // DP dihitung dari Grand Total
        $dpPersen = $sertakanDp ? (parseAngka($dataForm['dp_input'] ?? '0') ?? 0.0) : 0.0;
        unset($dataForm['dp_input']);
        $dpNominal = $sertakanDp ? round($grandTotal * ($dpPersen / 100)) : 0.0;
        if ($sertakanDp) {
            $dataForm['down_payment'] = formatRupiah($dpNominal);
            $dpPersenTampil = (floor($dpPersen) == $dpPersen)
                ? (string) (int) $dpPersen
                : rtrim(rtrim(number_format($dpPersen, 2, ',', '.'), '0'), ',');
            $dataForm['down_payment_persen'] = $dpPersenTampil . '%';
        }

        // Total Bayar = Grand Total dikurangi DP (kalau DP disertakan & > 0)
        $totalBayar = ($sertakanDp && $dpNominal > 0) ? ($grandTotal - $dpNominal) : $grandTotal;
        if ($sertakanTotalBayar) {
            $dataForm['total_bayar'] = formatRupiah($totalBayar);
        }

        // ----- BARU: Sisa Pelunasan = Grand Total - DP (checkbox terpisah dari Total Bayar) -----
        $sisaPelunasan = $grandTotal - $dpNominal;
        if ($sertakanSisaPelunasan) {
            $dataForm['sisa_pelunasan'] = formatRupiah($sisaPelunasan);
        }

        // ----- TERBILANG: prioritas sumber nilai -----
        // - DP & Sisa Pelunasan sama-sama disertakan -> pakai Sisa Pelunasan
        // - Hanya DP yang disertakan (Sisa Pelunasan tidak) -> pakai DP
        // - Selain itu (tidak pakai DP) -> pakai Total Bayar (perilaku lama)
        if ($sertakanDp && $dpNominal > 0 && $sertakanSisaPelunasan) {
            $nilaiUntukTerbilang = $sisaPelunasan;
        } elseif ($sertakanDp && $dpNominal > 0) {
            $nilaiUntukTerbilang = $dpNominal;
        } else {
            $nilaiUntukTerbilang = $totalBayar;
        }
        $dataForm['terbilang'] = terbilang($nilaiUntukTerbilang) . ' Rupiah';
    }

    // -----------------------------------------------------
    // 2b) HITUNG OTOMATIS: total_alat
    // -----------------------------------------------------
    if ($adaKuantitasOtomatis) {
        $totalKuantitasBulat = (floor($totalKuantitas) == $totalKuantitas)
            ? (string) (int) $totalKuantitas
            : rtrim(rtrim(number_format($totalKuantitas, 2, ',', '.'), '0'), ',');

        $dataForm['total_alat'] = $satuanKuantitas !== null
            ? ($totalKuantitasBulat . ' ' . $satuanKuantitas)
            : $totalKuantitasBulat;
    }

    // ${no_surat} format khusus (JD + kode manual + /bulan/tahun)
    if (!empty($dataForm['no_surat_manual'])) {
        $dataForm['no_surat'] = buatNoSuratKhusus($dataForm['no_surat_manual']);
    }
    unset($dataForm['no_surat_manual']); // jangan sampai ke-set sebagai placeholder biasa

    // -----------------------------------------------------
    // 3) FIELD BIASA (termasuk nomor surat & hasil hitung di atas)
    // -----------------------------------------------------
    $nomorUntukTampil = $dataForm['nomor'] ?? $nomorSurat;
    $fields = array_merge($dataForm, ['nomor' => $nomorUntukTampil, 'nomor_surat' => $nomorUntukTampil]);
    foreach ($fields as $key => $value) {
        $namaMacroDipakai = cariNamaMacroAsli($teksPolosTemplateAsli, $key) ?? $key;
        try {
            $processor->setValue($namaMacroDipakai, htmlspecialchars((string) $value, ENT_QUOTES));
        } catch (\Throwable $e) {
            // placeholder ${...} tidak ditemukan di template, lewati saja
        }
    }

    // ==========================================
// FORMAT NAMA FILE
// 001. PENAWARAN HARGA RIKSA UJI ALAT. PT ABC.docx
// ==========================================

    // Ambil nomor awal (001 dari 001/SP/ARP/VII/2026)
    $nomor = explode('/', $nomorSurat)[0];

    // Jenis surat dari tabel kode_surat.nama
    $jenis = strtoupper(trim($jenisSurat));

    // Nama perusahaan
// Prioritas:
// 1. nama_perusahaan (placeholder Word)
// 2. tujuan (kolom surat)
// 3. perusahaan
    $kandidatPerusahaan = [
        $dataForm['nama_perusahaan'] ?? '',
        $dataForm['nama_perusahaan_tujuan'] ?? '',
        $dataForm['nama_perusahaan_pihak_pertama'] ?? '',
        $dataForm['tujuan'] ?? '',
        $dataForm['perusahaan'] ?? '',
        $tujuanManual ?? '',
    ];
    $perusahaan = '';
    foreach ($kandidatPerusahaan as $kandidat) {
        if (trim((string) $kandidat) !== '') {
            $perusahaan = $kandidat;
            break;
        }
    }
    $perusahaan = strtoupper(trim($perusahaan));

    // Bersihkan karakter yang tidak boleh ada pada nama file
    $jenis = preg_replace('/[\\\\\/:*?"<>|]+/', '', $jenis);
    $perusahaan = preg_replace('/[\\\\\/:*?"<>|]+/', '', $perusahaan);

    // Hilangkan spasi berlebih
    $jenis = preg_replace('/\s+/', ' ', $jenis);
    $perusahaan = preg_replace('/\s+/', ' ', $perusahaan);

    // Susun nama file
    $namaFileHasil = $nomor;

    // Label revisi: kosong kalau revisi_ke = 0 (surat asli / belum pernah direvisi).
    // revisi_ke = 1 -> "[REV-01]", revisi_ke = 2 -> "[REV-02]", dst -- dipasang
    // sebagai AWALAN nama file (bukan disisipkan di tengah), supaya gampang
    // dibedakan sekilas dari daftar file di Drive. Dipakai untuk SEMUA revisi,
    // baik yang otomatis dipicu penolakan direksi maupun revisi manual biasa
    // (lewat centang "tandai revisi") -- keduanya sama-sama tetap 1 baris
    // "keluarga" surat yang sama, jadi penamaannya pun konsisten.
    // Nomor surat itu sendiri TIDAK pernah berubah -- hanya nama file yang
    // menandai ini revisi ke berapa.
    $awalanRevisi = $revisiKe > 0 ? sprintf('[REV-%02d] ', $revisiKe) : '';

    if ($awalanRevisi !== '') {
        $namaFileHasil = $awalanRevisi . $namaFileHasil;
    }

    if ($jenis !== '') {
        $namaFileHasil .= '. ' . $jenis;
    }

    if ($perusahaan !== '') {
        $namaFileHasil .= ' ' . $perusahaan;
    }

    $namaFileHasil .= '.docx';

    if (!is_dir(SURAT_KELUAR_DIR)) {
        @mkdir(SURAT_KELUAR_DIR, 0775, true);
    }

    $outputPath = SURAT_KELUAR_DIR . $namaFileHasil;

    $processor->saveAs($outputPath);

    replaceBraceOnlyPlaceholders($outputPath, $fields);

    // Hapus baris/paragraf ringkasan (PPN/PPH23/Diskon) yang tidak dicentang.
    $fieldRingkasanDihapus = [];
    if (!$sertakanPpn) {
        $fieldRingkasanDihapus[] = 'ppn';
    }
    if (!$sertakanPph23) {
        $fieldRingkasanDihapus[] = 'pph_23';
    }
    if (!$sertakanDiskon) {
        $fieldRingkasanDihapus[] = 'diskon';
        $fieldRingkasanDihapus[] = 'diskon_persen';
    }
    if (!$sertakanGrandTotal) {
        $fieldRingkasanDihapus[] = 'grand_total';
    }
    if (!$sertakanTotalBayar) {                      // ⬅ BARU
        $fieldRingkasanDihapus[] = 'total_bayar';
    }
    if (!$sertakanSisaPelunasan) {                    // ⬅ BARU
        $fieldRingkasanDihapus[] = 'sisa_pelunasan';
    }
    if (!$sertakanDp) {
        $fieldRingkasanDihapus[] = 'down_payment';
        $fieldRingkasanDihapus[] = 'down_payment_persen';
    }
    if (!empty($fieldRingkasanDihapus)) {
        hapusBarisTidakDisertakan($outputPath, $fieldRingkasanDihapus);
    }



    // Tahap 3 (opsional): gabungkan (merge) sel kolom "No" & kolom perusahaan
    // secara vertikal untuk baris-baris dengan nama perusahaan yang sama.
    // Nomor "no" di sini dihitung PER GRUP (sama seperti nomor yang ditulis
    // ke ${item_no} di Tahap 1), supaya deteksi kolom "no" di dalam docx
    // tetap cocok/akurat (cth: 1, 1, 2 -- bukan 1, 2, 3).
    if (!empty($items)) {
        $kolomKunciGabung = null;
        foreach (reset($items) as $namaKolom => $_) {
            if (preg_match('/perusahaan/i', $namaKolom)) {
                $kolomKunciGabung = $namaKolom;
                break;
            }
        }
        if ($kolomKunciGabung !== null) {
            $dataUntukMerge = [];
            $nomorUrutGrup = 0;
            $nilaiSebelumnya = null;
            foreach (array_values($items) as $i => $item) {
                $nilaiSekarang = trim((string) ($item[$kolomKunciGabung] ?? ''));
                if ($nilaiSekarang === '' || $nilaiSekarang !== $nilaiSebelumnya) {
                    $nomorUrutGrup++;
                }
                $dataUntukMerge[] = array_merge($item, ['no' => (string) $nomorUrutGrup]);
                $nilaiSebelumnya = $nilaiSekarang;
            }
            try {
                mergeGrupKolomVertikalDocx($outputPath, $dataUntukMerge, $kolomKunciGabung, ['no', $kolomKunciGabung]);
            } catch (\Throwable $e) {
                // Kalau gagal merge, biarkan dokumen tanpa merge -- jangan sampai
                // bikin generate surat gagal total.
            }
        }
    }

    return 'storage/surat_keluar/' . $namaFileHasil;
}

// ==========================================
// FORMAT KHUSUS ${no_surat}:
//   PREFIX_TETAP + KODE_MANUAL + "/" + bulan(2 digit) + "/" + tahun
// Contoh: JD75T4EM/04/2026
// "JD", bulan, tahun -> otomatis. Kode tengah -> diisi manual user.
// ==========================================
const PREFIX_NO_SURAT_KHUSUS = 'JD';

function buatNoSuratKhusus(string $kodeManual): string
{
    $kodeManual = strtoupper(trim($kodeManual));
    if ($kodeManual === '') {
        throw new RuntimeException("Kode nomor surat (bagian setelah \"JD\") wajib diisi.");
    }
    if (!preg_match('/^[A-Z0-9]+$/', $kodeManual)) {
        throw new RuntimeException("Kode nomor surat hanya boleh huruf & angka, tanpa spasi/simbol.");
    }
    return PREFIX_NO_SURAT_KHUSUS . $kodeManual . '/' . date('m') . '/' . date('Y');
}

function mergeGrupKolomVertikalDocx(string $docxFullPath, array $items, string $kolomKunci, array $kolomDigabung = []): void
{
    if (empty($items) || count($items) < 2) {
        return; // tidak ada yang perlu digabung kalau baris < 2
    }

    if (empty($kolomDigabung)) {
        $kolomDigabung = [$kolomKunci];
    }

    $items = array_values($items);
    $jumlahBaris = count($items);

    $nilaiKunci = array_map(
        fn($item) => trim((string) ($item[$kolomKunci] ?? '')),
        $items
    );

    $adaGrupSama = false;
    for ($i = 1; $i < $jumlahBaris; $i++) {
        if ($nilaiKunci[$i] !== '' && $nilaiKunci[$i] === $nilaiKunci[$i - 1]) {
            $adaGrupSama = true;
            break;
        }
    }
    if (!$adaGrupSama) {
        return;
    }

    if (!is_file($docxFullPath)) {
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($docxFullPath) !== true) {
        return;
    }
    $xmlMentah = $zip->getFromName('word/document.xml');
    if ($xmlMentah === false) {
        $zip->close();
        return;
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    if (!@$dom->loadXML($xmlMentah)) {
        $zip->close();
        return;
    }

    $xpath = new DOMXPath($dom);
    $nsWord = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xpath->registerNamespace('w', $nsWord);

    $ambilTeksSel = function (DOMElement $tc) use ($xpath): string {
        $teksNodes = $xpath->query('.//w:t', $tc);
        $teks = '';
        foreach ($teksNodes as $t) {
            $teks .= $t->textContent;
        }
        return trim($teks);
    };

    // Kumpulkan SEMUA tabel yang cocok (bisa lebih dari satu, cth tabel yang
    // sama diulang di beberapa halaman template) -- BEDA dari versi lama yang
    // berhenti di tabel pertama yang ketemu.
    $tabelTarget = []; // tiap elemen: ['baris' => [...], 'idxKolomKunci' => int]

    foreach ($xpath->query('//w:tbl') as $tbl) {
        $semuaBaris = [];
        foreach ($xpath->query('./w:tr', $tbl) as $tr) {
            $semuaBaris[] = $tr;
        }
        if (count($semuaBaris) < $jumlahBaris) {
            continue;
        }

        $kandidatBaris = array_slice($semuaBaris, -$jumlahBaris);

        $jumlahKolomMax = 0;
        foreach ($kandidatBaris as $tr) {
            $jumlahKolomMax = max($jumlahKolomMax, $xpath->query('./w:tc', $tr)->length);
        }

        $idxKolomKunciTabelIni = null;
        for ($c = 0; $c < $jumlahKolomMax; $c++) {
            $cocok = true;
            foreach ($kandidatBaris as $i => $tr) {
                $selKolom = $xpath->query('./w:tc', $tr);
                if ($selKolom->item($c) === null) {
                    $cocok = false;
                    break;
                }
                if ($ambilTeksSel($selKolom->item($c)) !== $nilaiKunci[$i]) {
                    $cocok = false;
                    break;
                }
            }
            if ($cocok) {
                $idxKolomKunciTabelIni = $c;
                break;
            }
        }

        if ($idxKolomKunciTabelIni !== null) {
            $tabelTarget[] = ['baris' => $kandidatBaris, 'idxKolomKunci' => $idxKolomKunciTabelIni];
        }
    }

    if (empty($tabelTarget)) {
        $zip->close();
        return;
    }

    $pastikanTcPr = function (DOMElement $tc) use ($dom, $nsWord): DOMElement {
        $tcPrList = $tc->getElementsByTagNameNS($nsWord, 'tcPr');
        if ($tcPrList->length > 0) {
            return $tcPrList->item(0);
        }
        $tcPr = $dom->createElementNS($nsWord, 'w:tcPr');
        if ($tc->firstChild) {
            $tc->insertBefore($tcPr, $tc->firstChild);
        } else {
            $tc->appendChild($tcPr);
        }
        return $tcPr;
    };

    $setVMerge = function (DOMElement $tcPr, ?string $nilai) use ($dom, $nsWord): void {
        foreach (iterator_to_array($tcPr->getElementsByTagNameNS($nsWord, 'vMerge')) as $el) {
            $tcPr->removeChild($el);
        }
        $vMerge = $dom->createElementNS($nsWord, 'w:vMerge');
        if ($nilai !== null) {
            $vMerge->setAttribute('w:val', $nilai);
        }
        if ($tcPr->firstChild) {
            $tcPr->insertBefore($vMerge, $tcPr->firstChild);
        } else {
            $tcPr->appendChild($vMerge);
        }
    };

    $kosongkanIsiSel = function (DOMElement $tc) use ($xpath): void {
        foreach (iterator_to_array($xpath->query('.//w:r', $tc)) as $run) {
            $run->parentNode->removeChild($run);
        }
    };

    // Proses SETIAP tabel target satu per satu (bisa lebih dari satu tabel)
    foreach ($tabelTarget as $target) {
        $barisTabelTarget = $target['baris'];

        $jumlahKolomMax = 0;
        foreach ($barisTabelTarget as $tr) {
            $jumlahKolomMax = max($jumlahKolomMax, $xpath->query('./w:tc', $tr)->length);
        }

        // Cari index kolom untuk tiap nama di $kolomDigabung, KHUSUS untuk
        // tabel ini (dihitung ulang per tabel karena urutan kolom bisa saja
        // sedikit berbeda antar tabel, meski biasanya sama).
        $indexKolomDigabung = [];
        foreach ($kolomDigabung as $namaKolom) {
            $nilaiKolomIni = array_map(
                fn($item) => trim((string) ($item[$namaKolom] ?? '')),
                $items
            );

            for ($c = 0; $c < $jumlahKolomMax; $c++) {
                $cocok = true;
                foreach ($barisTabelTarget as $i => $tr) {
                    $selKolom = $xpath->query('./w:tc', $tr);
                    if ($selKolom->item($c) === null) {
                        $cocok = false;
                        break;
                    }
                    if ($ambilTeksSel($selKolom->item($c)) !== $nilaiKolomIni[$i]) {
                        $cocok = false;
                        break;
                    }
                }
                if ($cocok) {
                    $indexKolomDigabung[$namaKolom] = $c;
                    break;
                }
            }
        }

        if (empty($indexKolomDigabung)) {
            continue; // tabel ini dilewati, tetap lanjut proses tabel berikutnya
        }

        $jumlahBarisTarget = count($barisTabelTarget);
        $i = 0;
        while ($i < $jumlahBarisTarget) {
            $j = $i + 1;
            while ($j < $jumlahBarisTarget && $nilaiKunci[$j] !== '' && $nilaiKunci[$j] === $nilaiKunci[$i]) {
                $j++;
            }
            $panjangGrup = $j - $i;

            if ($panjangGrup > 1) {
                foreach ($indexKolomDigabung as $idxKolom) {
                    for ($baris = $i; $baris < $j; $baris++) {
                        $tr = $barisTabelTarget[$baris];
                        $selList = $xpath->query('./w:tc', $tr);
                        if ($selList->item($idxKolom) === null) {
                            continue;
                        }
                        $tc = $selList->item($idxKolom);
                        $tcPr = $pastikanTcPr($tc);

                        if ($baris === $i) {
                            $setVMerge($tcPr, 'restart');
                        } else {
                            $setVMerge($tcPr, null);
                            $kosongkanIsiSel($tc);
                        }
                    }
                }
            }

            $i = $j;
        }
    }

    $xmlBaru = $dom->saveXML();
    $zip->addFromString('word/document.xml', $xmlBaru);
    $zip->close();
}

// ==========================================
// HAPUS BARIS/PARAGRAF YANG MEMUAT PLACEHOLDER TERTENTU DARI FILE .docx
// HASIL GENERATE. Dipakai supaya baris PPN / PPH 23 / Diskon benar-benar
// HILANG dari dokumen kalau checklist terkait TIDAK dicentang -- bukan
// cuma dikosongkan nilainya.
// ==========================================
function hapusBarisTidakDisertakan(string $docxFullPath, array $daftarFieldDihapus): void
{
    $daftarFieldDihapus = array_values(array_filter(
        array_map('strval', $daftarFieldDihapus),
        fn($f) => trim($f) !== ''
    ));
    if (empty($daftarFieldDihapus) || !is_file($docxFullPath)) {
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($docxFullPath) !== true) {
        return;
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        return;
    }

    $xml = fixBrokenBraceMacros($xml);

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    if (!@$dom->loadXML($xml)) {
        $zip->close();
        return;
    }

    $xpath = new DOMXPath($dom);
    $nsWord = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xpath->registerNamespace('w', $nsWord);

    $ambilTeks = function (DOMElement $el) use ($xpath): string {
        $teks = '';
        foreach ($xpath->query('.//w:t', $el) as $t) {
            $teks .= $t->textContent;
        }
        return $teks;
    };

    $polaDicari = [];
    foreach ($daftarFieldDihapus as $namaField) {
        $q = preg_quote($namaField, '/');
        $polaDicari[] = '/\$\{\s*' . $q . '\s*\}/i';
        $polaDicari[] = '/(?<!\$)\{\s*' . $q . '\s*\}/i';
    }

    $cocokPola = function (string $teks) use ($polaDicari): bool {
        foreach ($polaDicari as $p) {
            if (preg_match($p, $teks)) {
                return true;
            }
        }
        return false;
    };

    // 1) Baris tabel (w:tr) yang memuat placeholder -> hapus seluruh baris.
    $trDihapus = [];
    foreach ($xpath->query('//w:tr') as $tr) {
        if ($cocokPola($ambilTeks($tr))) {
            $trDihapus[] = $tr;
        }
    }
    foreach ($trDihapus as $tr) {
        if ($tr->parentNode) {
            $tr->parentNode->removeChild($tr);
        }
    }

    // 2) Paragraf (w:p) DI LUAR tabel yang memuat placeholder -> hapus paragraf.
    $pDihapus = [];
    foreach ($xpath->query('//w:p') as $p) {
        if (!$p->parentNode) {
            continue;
        }
        $adaLeluhurTabel = false;
        $cek = $p->parentNode;
        while ($cek) {
            if ($cek instanceof DOMElement && $cek->localName === 'tc') {
                $adaLeluhurTabel = true;
                break;
            }
            $cek = $cek->parentNode;
        }
        if ($adaLeluhurTabel) {
            continue;
        }
        if ($cocokPola($ambilTeks($p))) {
            $pDihapus[] = $p;
        }
    }
    foreach ($pDihapus as $p) {
        if ($p->parentNode) {
            $p->parentNode->removeChild($p);
        }
    }

    $xmlBaru = $dom->saveXML();
    $zip->addFromString('word/document.xml', $xmlBaru);
    $zip->close();
}


// ==========================================
// (OPSIONAL) KONVERSI DOCX -> PDF VIA LIBREOFFICE HEADLESS
// ==========================================
function convertDocxToPdf(string $docxFullPath): ?string
{
    $outputDir = dirname($docxFullPath);
    $cmd = 'libreoffice --headless --convert-to pdf --outdir ' .
        escapeshellarg($outputDir) . ' ' . escapeshellarg($docxFullPath) . ' 2>&1';

    exec($cmd, $output, $returnCode);

    $pdfPath = preg_replace('/\.docx$/', '.pdf', $docxFullPath);

    return ($returnCode === 0 && file_exists($pdfPath)) ? $pdfPath : null;
}

// ==========================================
// HELPER GENERIK: upload file ke direktori storage tertentu
// ==========================================
function uploadFileKeStorage(array $file, string $direktoriTujuanAbsolut, string $prefixPathRelatif, array $allowedExt = ['docx', 'pdf']): string
{
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt, true)) {
        $daftarExt = implode(', ', array_map(fn($e) => '.' . $e, $allowedExt));
        throw new RuntimeException("Format file tidak didukung. Hanya {$daftarExt}.");
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Terjadi kesalahan saat upload file.");
    }

    if (!is_dir($direktoriTujuanAbsolut)) {
        @mkdir($direktoriTujuanAbsolut, 0775, true);
    }

    $namaFile = 'tpl_' . uniqid() . '.' . $ext;
    $tujuan = $direktoriTujuanAbsolut . $namaFile;

    if (!move_uploaded_file($file['tmp_name'], $tujuan)) {
        throw new RuntimeException("Gagal menyimpan file ke server.");
    }

    return $prefixPathRelatif . $namaFile;
}

// ==========================================
// UPLOAD FILE TEMPLATE MASTER (.docx / .pdf) -> storage/templates/
// ==========================================
/* function uploadTemplateFile(array $file): string
{
    return uploadFileKeStorage($file, TEMPLATE_DIR, 'storage/templates/', ['docx', 'pdf']);
} */

// ==========================================
// UPLOAD LAMPIRAN SURAT MASUK -> storage/surat_masuk/
// ==========================================
function uploadSuratMasukFile(array $file): string
{
    return uploadFileKeStorage($file, SURAT_MASUK_DIR, 'storage/surat_masuk/', ['docx', 'doc', 'pdf']);
}


// ==========================================
// SCAN PLACEHOLDER ${...} ATAU {...} DARI FILE .DOCX
// Sekarang MEMISAHKAN hasil scan jadi 2 kelompok:
//   - 'fields'       : field biasa (satu nilai)
//   - 'table_fields' : field tabel berulang, yaitu semua placeholder yang
//                      diawali "item_" (cth item_deskripsi, item_qty, dst),
//                      TIDAK termasuk item_no & item_sub_total karena
//                      keduanya diisi otomatis oleh sistem.
// Field seperti total/ppn/pph_23/total_bayar/terbilang/total_alat/nomor/
// nomor_surat SENGAJA tidak dimasukkan ke 'fields' (lihat FIELD_OTOMATIS_SISTEM)
// supaya tidak muncul sebagai input yang bisa diisi manual di form "Buat Surat" --
// nilainya selalu dihitung otomatis oleh generateSuratDocx().
// Return: ['fields' => [...], 'table_fields' => [...]]
// ==========================================
function scanPlaceholdersFromDocx(string $fullPath): array
{
    $kosong = ['fields' => [], 'table_fields' => [], 'invoice_fields' => []];

    if (!file_exists($fullPath) || pathinfo($fullPath, PATHINFO_EXTENSION) !== 'docx') {
        return $kosong;
    }

    $zip = new ZipArchive();
    if ($zip->open($fullPath) !== true) {
        return $kosong;
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        return $kosong;
    }

    $plain = preg_replace('/<[^>]+>/', '', $xml);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_XML1, 'UTF-8');

    // Grup 1: ${nama_field}   Grup 2: {nama_field} tanpa dolar
    preg_match_all('/\$\{\s*([a-zA-Z0-9_]+)\s*\}|(?<!\$)\{\s*([a-zA-Z0-9_]+)\s*\}/', $plain, $matches);

    $mentah = array_merge($matches[1], $matches[2]);
    $mentah = array_values(array_filter($mentah, fn($f) => $f !== ''));
    $semuaField = array_values(array_unique($mentah));

    // Field yang selalu diisi otomatis oleh sistem (nomor surat & hasil hitungan
    // total/ppn/pph/total_bayar/terbilang/total_alat) tidak boleh jadi input form manual.
    $semuaField = array_values(array_filter(
        $semuaField,
        fn($f) => !in_array(strtolower($f), FIELD_OTOMATIS_SISTEM, true)
    ));

    $fields = [];
    $tableFields = [];
    $invoiceFields = [];
    foreach ($semuaField as $f) {
        if (stripos($f, PREFIX_KOLOM_TABEL) === 0) {
            $tanpaPrefix = substr($f, strlen(PREFIX_KOLOM_TABEL));
            // item_no & item_sub_total dihitung otomatis, tidak perlu jadi input form
            if (in_array($f, [ANCHOR_KOLOM_TABEL, KOLOM_SUBTOTAL], true)) {
                continue;
            }
            $tableFields[] = $tanpaPrefix;
        } elseif (stripos($f, PREFIX_INVOICE) === 0) {
            $invoiceFields[] = $f;
        } else {
            $fields[] = $f;
        }
    }

    $blocks = scanBlocksFromDocx($fullPath);

    // Nama field yang harus DIKECUALIKAN dari daftar 'fields' biasa karena
    // sudah tercakup oleh mekanisme blok:
    //  - nama penanda blok itu sendiri (cth "blok_pemeriksa" dari ${blok_pemeriksa})
    //  - semua field YANG ADA DI DALAM blok (cth "pemeriksa_nama", "pemeriksa_jabatan")
    // Tanpa ini, field-field tsb akan ikut muncul dobel sebagai input form biasa
    // DI LUAR kotak blok, padahal seharusnya hanya muncul di dalam kotak blok.
    $fieldDikecualikanKarenaBlok = [];
    foreach ($blocks as $namaBlok => $fieldDalamBlok) {
        $fieldDikecualikanKarenaBlok[] = PREFIX_BLOK . $namaBlok;
        foreach ($fieldDalamBlok as $f) {
            $fieldDikecualikanKarenaBlok[] = $f;
        }
    }
    $fields = array_values(array_filter(
        $fields,
        fn($f) => !in_array($f, $fieldDikecualikanKarenaBlok, true)
    ));

    return [
        'fields' => $fields,
        'table_fields' => array_values(array_unique($tableFields)),
        'blocks' => $blocks,
        'invoice_fields' => array_values(array_unique($invoiceFields)),
    ];
}

// ==========================================
// SCAN BLOK ${blok_nama} ... ${/blok_nama} DARI FILE .DOCX
// Beda dari tabel item_...: blok ini untuk list/paragraf bernomor yang
// BUKAN tabel Word, tapi tetap bisa ditambah baris bebas via cloneBlock().
// Return: ['pemeriksa' => ['nama', 'jabatan'], ...]
// (nama field DI DALAM blok tidak pakai prefix apa pun, cukup nama biasa,
// TAPI harus unik dan sebaiknya deskriptif karena akan diisi lewat
// $processor->setValue($namaField . '#' . $idx, ...) mengikuti pola PHPWord.)
// ==========================================
function scanBlocksFromDocx(string $fullPath): array
{
    if (!file_exists($fullPath) || pathinfo($fullPath, PATHINFO_EXTENSION) !== 'docx') {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($fullPath) !== true) {
        return [];
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return [];
    }

    $plain = preg_replace('/<[^>]+>/', '', $xml);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $blocks = [];
    // Tangkap tiap pasangan ${blok_NAMA} ... ${/blok_NAMA}
    preg_match_all('/\$\{blok_([a-zA-Z0-9_]+)\}(.*?)\$\{\/blok_\1\}/s', $plain, $m, PREG_SET_ORDER);
    foreach ($m as $match) {
        $namaBlok = $match[1];
        $isiBlok = $match[2];
        preg_match_all('/\$\{\s*([a-zA-Z0-9_]+)\s*\}/', $isiBlok, $fieldMatch);
        $fieldsDalamBlok = array_values(array_unique(array_filter($fieldMatch[1], fn($f) => $f !== '')));
        if (!empty($fieldsDalamBlok)) {
            $blocks[$namaBlok] = $fieldsDalamBlok;
        }
    }

    return $blocks;
}

// ==========================================
// DETEKSI FIELD OTOMATIS APA SAJA YANG BENAR-BENAR DIPAKAI DI TEMPLATE
// (total, ppn, pph_23, total_bayar, terbilang, total_alat), supaya UI
// (form Buat Surat & preview) bisa menyesuaikan tampilan per template --
// misal template Penawaran tanpa ${pph_23} tidak akan menampilkan baris PPH 23.
// ==========================================
function scanAutoFieldsFromDocx(string $fullPath): array
{
    if (!file_exists($fullPath) || pathinfo($fullPath, PATHINFO_EXTENSION) !== 'docx') {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($fullPath) !== true) {
        return [];
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return [];
    }

    $plain = preg_replace('/<[^>]+>/', '', $xml);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_XML1, 'UTF-8');

    preg_match_all('/\$\{\s*([a-zA-Z0-9_]+)\s*\}|(?<!\$)\{\s*([a-zA-Z0-9_]+)\s*\}/', $plain, $matches);
    $mentah = array_merge($matches[1], $matches[2]);
    $ditemukan = array_unique(array_map('strtolower', array_filter($mentah, fn($f) => $f !== '')));

    return array_values(array_intersect(FIELD_OTOMATIS_SISTEM, $ditemukan));
}

// ==========================================
// RAPIKAN PLACEHOLDER {...} (TANPA DOLAR) YANG TERPECAH ANTAR-RUN WORD
// ==========================================
function fixBrokenBraceMacros(string $xml): string
{
    return preg_replace_callback(
        '/\{([^{}]*)\}/U',
        function ($m) {
            return strip_tags($m[0]);
        },
        $xml
    );
}

// ==========================================
// ISI PLACEHOLDER {...} (TANPA DOLAR) LANGSUNG DI XML MENTAH FILE .DOCX
// ==========================================
function replaceBraceOnlyPlaceholders(string $docxFullPath, array $fields): void
{
    $zip = new ZipArchive();
    if ($zip->open($docxFullPath) !== true) {
        throw new RuntimeException("Gagal membuka file hasil generate untuk mengisi placeholder {...}.");
    }

    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        throw new RuntimeException("Gagal membaca isi dokumen hasil generate.");
    }

    $xml = fixBrokenBraceMacros($xml);

    foreach ($fields as $key => $value) {
        $xml = str_replace(
            '{' . $key . '}',
            htmlspecialchars((string) $value, ENT_QUOTES),
            $xml
        );
    }

    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
}

// ==========================================
// GANTI NAMA PLACEHOLDER ${lama} -> ${baru} (dan {lama} -> {baru} tanpa
// dolar) LANGSUNG DI XML MENTAH FILE .DOCX. Dipakai saat Admin mengedit
// nama field template lewat modal Edit Template.
// $mapPenggantian = ['nama_perusahaan_tujuan' => 'nama_perusahaan', ...]
// Aman untuk placeholder biasa, kolom tabel (item_...), maupun field di
// dalam blok (blok_...), karena rename dilakukan di level nama field murni
// -- prefix seperti "item_" atau isi blok tetap ikut terganti otomatis
// selama nama field lengkapnya (termasuk prefix) ada di dalam mapping.
// ==========================================
function renamePlaceholdersInDocx(string $docxFullPath, array $mapPenggantian): void
{
    if (empty($mapPenggantian) || !is_file($docxFullPath)) {
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($docxFullPath) !== true) {
        throw new RuntimeException("Gagal membuka file template untuk mengubah nama field.");
    }

    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        throw new RuntimeException("Gagal membaca isi template.");
    }

    // Rapikan dulu placeholder {...} yang mungkin terpecah antar-run Word,
    // supaya regex penggantian di bawah bisa mengenali nama field utuh.
    $xml = fixBrokenBraceMacros($xml);

    // Urutkan mapping dari nama field TERPANJANG dulu, supaya field yang
    // namanya adalah prefix dari field lain (mis. "nama" vs "nama_perusahaan")
    // tidak salah tergantikan sebagian.
    $namaLamaList = array_keys($mapPenggantian);
    usort($namaLamaList, fn($a, $b) => strlen($b) <=> strlen($a));

    foreach ($namaLamaList as $namaLama) {
        $namaBaru = $mapPenggantian[$namaLama];
        $namaLamaQuoted = preg_quote($namaLama, '/');

        // Ganti ${namaLama} -> ${namaBaru}
        $xml = preg_replace(
            '/\$\{\s*' . $namaLamaQuoted . '\s*\}/i',
            '${' . $namaBaru . '}',
            $xml
        );

        // Ganti {namaLama} (tanpa dolar) -> {namaBaru}, tapi JANGAN sampai
        // mengenai ${namaLama} yang sudah diproses di atas (negative lookbehind $).
        $xml = preg_replace(
            '/(?<!\$)\{\s*' . $namaLamaQuoted . '\s*\}/i',
            '{' . $namaBaru . '}',
            $xml
        );

        // Ganti penanda blok ${blok_namaLama} ... ${/blok_namaLama} kalau
        // nama field yang diedit adalah nama blok itu sendiri.
        $xml = preg_replace(
            '/\$\{blok_' . $namaLamaQuoted . '\}/i',
            '${blok_' . $namaBaru . '}',
            $xml
        );
        $xml = preg_replace(
            '/\$\{\/blok_' . $namaLamaQuoted . '\}/i',
            '${/blok_' . $namaBaru . '}',
            $xml
        );
    }

    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
}

// ==========================================
// LABEL OTOMATIS UNTUK FIELD (dari nama_field -> "Nama Field")
// ==========================================
function labelFromFieldName(string $field): string
{
    return ucwords(str_replace('_', ' ', $field));
}

// ==========================================
// BANGUN STRUKTUR fields_json (VERSI BARU, ADA TABEL):
// {
//   "fields": [{"field": "ditujukan_kepada", "label": "Ditujukan Kepada"}, ...],
//   "table_fields": [{"field": "deskripsi", "label": "Descriptions"}, ...]
// }
// $hasilScan = hasil dari scanPlaceholdersFromDocx()
// ==========================================
function buildFieldsWithDefaultLabels(array $hasilScan): array
{
    $buatLabel = function (array $namaField) {
        $result = [];
        foreach ($namaField as $f) {
            $result[] = ['field' => $f, 'label' => labelFromFieldName($f)];
        }
        return $result;
    };

    $blocksLabel = [];
    foreach ($hasilScan['blocks'] ?? [] as $namaBlok => $fieldListBlok) {
        // "no" dikecualikan dari daftar input form blok, karena diisi OTOMATIS
        // oleh sistem (nomor urut baris), sama seperti item_no pada tabel. User
        // cukup tulis ${no} di template, tidak perlu isi manual di form. (Field
        // ini tetap dipertahankan di scanBlocksFromDocx() supaya juga otomatis
        // ter-exclude dari daftar field biasa di luar blok -- lihat
        // scanPlaceholdersFromDocx().)
        $fieldListBlokTanpaNo = array_values(array_filter(
            $fieldListBlok,
            fn($f) => strtolower($f) !== 'no'
        ));
        if (!empty($fieldListBlokTanpaNo)) {
            $blocksLabel[$namaBlok] = $buatLabel($fieldListBlokTanpaNo);
        }
    }

    return [
        'fields' => $buatLabel($hasilScan['fields'] ?? []),
        'table_fields' => $buatLabel($hasilScan['table_fields'] ?? []),
        'blocks' => $blocksLabel,
        'invoice_fields' => $hasilScan['invoice_fields'] ?? [],
    ];
}

// ==========================================
// GABUNGKAN HASIL SCAN BARU DENGAN LABEL LAMA (kalau template di-upload ulang)
// ==========================================
function mergeFieldsPreservingLabels(array $hasilScanBaru, array $fieldsLamaJson): array
{
    $ambilLabelLama = function (array $itemsLama) {
        $peta = [];
        foreach ($itemsLama as $item) {
            if (isset($item['field'], $item['label'])) {
                $peta[$item['field']] = $item['label'];
            }
        }
        return $peta;
    };

    $labelLamaFields = $ambilLabelLama($fieldsLamaJson['fields'] ?? []);
    $labelLamaTabel = $ambilLabelLama($fieldsLamaJson['table_fields'] ?? []);

    $gabung = function (array $namaBaru, array $labelLama) {
        $result = [];
        foreach ($namaBaru as $f) {
            $result[] = ['field' => $f, 'label' => $labelLama[$f] ?? labelFromFieldName($f)];
        }
        return $result;
    };

    return [
        'fields' => $gabung($hasilScanBaru['fields'] ?? [], $labelLamaFields),
        'table_fields' => $gabung($hasilScanBaru['table_fields'] ?? [], $labelLamaTabel),
        'invoice_fields' => $hasilScanBaru['invoice_fields'] ?? [],
    ];
}

function muatFieldsTemplateLive(PDO $pdo, array $kodeRow): array
{
    $decodedLama = !empty($kodeRow['fields_json']) ? (json_decode($kodeRow['fields_json'], true) ?: []) : [];
    $fallback = [
        'fields' => $decodedLama['fields'] ?? [],
        'table_fields' => $decodedLama['table_fields'] ?? [],
        'blocks' => $decodedLama['blocks'] ?? [],
        'invoice_fields' => $decodedLama['invoice_fields'] ?? [],
    ];

    if (empty($kodeRow['drive_file_id']) || ($kodeRow['format'] ?? '') !== 'word_pdf') {
        return $fallback;
    }

    try {
        return arp_dengan_template_sementara($kodeRow['drive_file_id'], function ($fullPath) use ($pdo, $kodeRow, $decodedLama) {
            $hasilScanBaru = scanPlaceholdersFromDocx($fullPath);
            $digabung = mergeFieldsPreservingLabels($hasilScanBaru, $decodedLama);
            $digabung['blocks'] = buildFieldsWithDefaultLabels($hasilScanBaru)['blocks'];

            $jsonBaru = json_encode($digabung, JSON_UNESCAPED_UNICODE);
            if (!empty($kodeRow['template_id']) && $jsonBaru !== ($kodeRow['fields_json'] ?? null)) {
                try {
                    $pdo->prepare("UPDATE template_master SET fields_json = ? WHERE id = ?")
                        ->execute([$jsonBaru, (int) $kodeRow['template_id']]);
                } catch (\Throwable $e) {
                }
            }

            return $digabung;
        });
    } catch (\Throwable $e) {
        // Drive lagi bermasalah -- pakai cache lama, jangan bikin halaman error total.
        return $fallback;
    }
}

// ==========================================
// BACA TEKS "Perihal : ..." STATIS DI DALAM WORD (fallback)
// ==========================================
function extractPerihalFromDocxText(string $fullPath): ?string
{
    if (!file_exists($fullPath) || pathinfo($fullPath, PATHINFO_EXTENSION) !== 'docx') {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($fullPath) !== true) {
        return null;
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        return null;
    }

    $xml = str_replace('</w:p>', "</w:p>\n", $xml);
    $plain = preg_replace('/<[^>]+>/', '', $xml);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_XML1, 'UTF-8');

    foreach (preg_split('/\r\n|\r|\n/', $plain) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^perihal\s*[:\-]\s*(.+)$/i', $line, $m)) {
            $value = trim($m[1]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return null;
}


// ==========================================
// HELPER: escape output aman untuk HTML
// ==========================================
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// ==========================================
// HELPER: bikin href aman untuk berkas surat. Semua surat sekarang
// disimpan di Google Drive (bukan storage lokal), jadi file_hasil isinya
// URL (https://...). Data lama yang masih path lokal tetap didukung
// sebagai fallback supaya link lama tidak patah.
// ==========================================
function hrefBerkas(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '#';
    }
    return (stripos($path, 'http://') === 0 || stripos($path, 'https://') === 0)
        ? $path
        : '../' . $path;
}

// ==========================================
// URL unduh LANGSUNG (force-download) dari Google Drive, bukan link preview.
// ==========================================
function urlUnduhLangsungDrive(?string $driveFileId): ?string
{
    $driveFileId = trim((string) $driveFileId);
    return $driveFileId !== '' ? ('https://drive.google.com/uc?export=download&id=' . urlencode($driveFileId)) : null;
}

// Fallback: ambil file_id dari URL Drive kalau drive_file_id kosong (mis. data lama / surat masuk manual).
function driveFileIdDariUrl(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '')
        return null;
    if (preg_match('#/d/([a-zA-Z0-9_-]{10,})#', $url, $m))
        return $m[1];
    if (preg_match('#[?&]id=([a-zA-Z0-9_-]{10,})#', $url, $m))
        return $m[1];
    return null;
}

// ==========================================
// IMPORT DATA KLIEN: PETA HEADER FILE -> KOLOM Data_Klien
// Hanya header yang ADA di daftar ini yang akan dibaca. Header lain di
// file import (kolom tak dikenal) otomatis diabaikan, tidak masuk data.
// ==========================================
function petaHeaderImportKlien(): array
{
    // Catatan: key di sini HARUS dalam bentuk yang sudah dinormalisasi lewat
    // normalisasiHeaderKlien() -> huruf besar semua, tanpa titik/titik dua,
    // tanpa spasi di sekitar "/". Nilai (value) = nama kolom di tabel Data_Klien.
    // Boleh tambah variasi header baru di sini kapan saja tanpa mengubah kode lain.
    return [
        'NAMA PERUSAHAAN' => 'nama_perusahaan',
        'PERUSAHAAN' => 'nama_perusahaan',
        'NAMA KLIEN' => 'nama_perusahaan',

        'NAMA PIC' => 'pic_nama',
        'PIC' => 'pic_nama',

        'JABATAN' => 'jabatan_pic',
        'JABATAN PIC' => 'jabatan_pic',

        'NO HP/WHATSAPP' => 'pic_whatsapp',
        'HP/WHATSAPP' => 'pic_whatsapp',
        'NO HP' => 'pic_whatsapp',
        'WHATSAPP' => 'pic_whatsapp',
        'NO WA' => 'pic_whatsapp',
        'WA' => 'pic_whatsapp',

        'EMAIL' => 'pic_email',
        'EMAIL PIC' => 'pic_email',
        'GMAIL' => 'pic_email',

        'STATUS CLIENT' => 'status',
        'STATUS' => 'status',
    ];
}

function normalisasiHeaderKlien(string $teks): string
{
    $teks = strtoupper(trim($teks));
    // Samakan variasi tanda baca (titik, titik dua) supaya header seperti
    // "No. HP/WhatsApp", "no hp/whatsapp:", "NO HP / WHATSAPP" tetap terbaca sama.
    $teks = str_replace([':', '.'], '', $teks);
    $teks = preg_replace('/\s*\/\s*/', '/', $teks); // "HP / WA" -> "HP/WA"
    $teks = preg_replace('/\s+/', ' ', $teks);
    return trim($teks);
}

// ==========================================
// BACA BARIS FILE IMPORT (.xlsx / .csv) -> array baris,
// tiap baris = array asosiatif ['A' => nilai, 'B' => nilai, ...]
// mengikuti huruf kolom aslinya (bukan reindex 0,1,2), supaya
// pemetaan header -> kolom tetap konsisten walau ada kolom kosong.
// ==========================================
function bacaBarisSpreadsheet(string $path, string $ext): array
{
    $ext = strtolower($ext);
    if ($ext === 'csv') {
        return bacaBarisCsv($path);
    }
    if ($ext === 'xlsx') {
        return bacaBarisXlsx($path);
    }
    throw new RuntimeException('Format file tidak didukung. Gunakan .xlsx atau .csv.');
}

function arpKolomIndexKeHuruf(int $index): string
{
    $huruf = '';
    $index++;
    while ($index > 0) {
        $sisa = ($index - 1) % 26;
        $huruf = chr(65 + $sisa) . $huruf;
        $index = intdiv($index - 1, 26);
    }
    return $huruf;
}

function bacaBarisCsv(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Gagal membaca file CSV.');
    }
    // Buang BOM UTF-8 kalau ada (biasa muncul dari export Excel)
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    $firstLine = strtok($content, "\r\n");
    // Deteksi delimiter otomatis: ";" (umum di Excel lokal ID) atau ","
    $delimiter = (substr_count((string) $firstLine, ';') > substr_count((string) $firstLine, ',')) ? ';' : ',';

    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cols = str_getcsv($line, $delimiter);
        $baris = [];
        foreach ($cols as $i => $val) {
            $baris[arpKolomIndexKeHuruf($i)] = trim((string) $val);
        }
        $rows[] = $baris;
    }
    return $rows;
}

function bacaBarisXlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Gagal membuka file Excel (.xlsx). Pastikan file tidak rusak.');
    }

    // Ambil shared strings (teks yang dipakai berulang di file Excel)
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ssObj = @simplexml_load_string($ssXml);
        if ($ssObj !== false) {
            foreach ($ssObj->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                } else {
                    $teks = '';
                    foreach ($si->r as $r) {
                        $teks .= (string) $r->t;
                    }
                    $sharedStrings[] = $teks;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Gagal membaca isi sheet pertama pada file Excel.');
    }

    $xmlObj = @simplexml_load_string($sheetXml);
    if ($xmlObj === false) {
        throw new RuntimeException('File Excel tidak valid atau rusak.');
    }

    $rows = [];
    foreach ($xmlObj->sheetData->row as $row) {
        $baris = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r']; // contoh "B4"
            preg_match('/^([A-Z]+)/', $ref, $m);
            $kolomHuruf = $m[1] ?? '';
            $tipe = (string) $c['t'];

            $nilai = '';
            if (isset($c->v)) {
                $mentah = (string) $c->v;
                $nilai = ($tipe === 's') ? ($sharedStrings[(int) $mentah] ?? '') : $mentah;
            } elseif ($tipe === 'inlineStr' && isset($c->is->t)) {
                $nilai = (string) $c->is->t;
            }
            if ($kolomHuruf !== '') {
                $baris[$kolomHuruf] = trim($nilai);
            }
        }
        if (!empty($baris)) {
            $rows[] = $baris;
        }
    }
    return $rows;
}

// ==========================================
// TEMPEL TANDA TANGAN DIGITAL (TTD) DIREKSI KE FILE SURAT YANG SUDAH ADA DI DRIVE
//
// Dipanggil dari direksi/approval.php SESUDAH surat berstatus "Disetujui".
// Bukan bikin surat baru -- file docx yang SUDAH diunggah ke Drive saat surat
// dibuat (lihat admin/surat.php, kolom surat.drive_file_id) diunduh sementara,
// macro gambar ${ttd_direksi} di dalamnya diganti dengan gambar tanda tangan
// direksi (Users.ttd_digital), lalu file itu TIMPA balik ke Drive dengan
// file_id yang SAMA (arp_timpa_konten_drive) supaya link yang sudah dipakai
// di mana-mana (email, notifikasi, tabel surat) tetap valid dan langsung
// menampilkan versi bertanda tangan.
//
// PENTING (best-effort, TIDAK PERNAH melempar exception): dipanggil SESUDAH
// transaksi approval di-commit, sama seperti pola arp_generate_dan_unggah_surat_cuti()
// di includes/cuti_surat_helper.php. Kalau gagal (mis. direksi belum upload
// ttd, atau macro ${ttd_direksi} belum ada di template surat itu), approval
// TETAP sah -- hanya saja suratnya belum otomatis bertanda tangan. Pesan
// kegagalan bisa diambil lewat arp_drive_last_error().
//
// SYARAT SUPAYA BERHASIL:
// 1) Kolom Users.ttd_digital & Users.jabatan_ttd sudah ada (lihat migrations/
//    2026_xx_xx_add_ttd_digital.sql) dan direksi sudah upload tanda tangan
//    lewat direksi/profile.php.
// 2) Template Word surat itu (di Google Drive) punya macro GAMBAR bernama
//    persis ${ttd_direksi} di kolom tanda tangan -- ditulis sebagai teks
//    biasa (BUKAN di dalam gambar/kotak yang sudah ada), satu potongan utuh
//    (tidak boleh sebagian hurufnya beda format dari yang lain, karena Word
//    akan memecahnya jadi beberapa "run" terpisah dan PhpWord jadi tidak
//    mengenalinya sebagai satu macro). Cara paling aman: ketik dulu di
//    Notepad, copy, lalu tempel ke Google Docs/Word pakai "Paste without
//    formatting" (Ctrl+Shift+V).
// ==========================================
// ==========================================
// BENERIN BUG: KONVERSI GAMBAR VML -> DRAWINGML DI FILE .DOCX
//
// PhpWord\TemplateProcessor::setImageValue() (dipanggil di
// arp_tempel_ttd_ke_surat() di bawah) menyisipkan gambar TTD pakai template
// XML lama bawaan library:
//   <w:pict><v:shape type="#_x0000_t75" style="width:...;height:..."
//     stroked="f" filled="f"><v:imagedata r:id="rIdX" o:title=""/></v:shape>
//   </w:pict>
// Ini format VML. Microsoft Word masih membacanya (demi kompatibilitas
// mundur ke Word lama), tapi Google Docs/Drive TIDAK bisa me-render VML --
// jadi gambar TTD-nya hilang/kosong kalau surat dibuka lewat Drive/Docs,
// padahal terlihat normal kalau file yang sama dibuka di Microsoft Word.
//
// Fungsi ini dipanggil SETELAH $processor->saveAs(): membuka lagi file
// .docx yang baru disimpan itu sebagai ZIP, mencari blok VML dengan
// fingerprint PERSIS seperti di atas (jadi tidak akan salah mengganti
// gambar/shape lain yang mungkin sudah ada di template), lalu menggantinya
// jadi <w:drawing> (DrawingML, format modern) yang tetap menunjuk ke
// relationship gambar (r:id/r:embed) YANG SAMA. Tidak perlu mengubah
// word/_rels/document.xml.rels sama sekali karena baik VML maupun
// DrawingML sama-sama cuma referensi ke relationship gambar biasa.
//
// @return int jumlah blok VML yang berhasil dikonversi (0 = tidak ada
//             gambar VML ditemukan; bukan berarti error).
// ==========================================
function arp_ganti_vml_ttd_ke_drawingml(string $pathDocx): int
{
    $zip = new ZipArchive();
    if ($zip->open($pathDocx) !== true) {
        throw new RuntimeException("Tidak bisa membuka $pathDocx sebagai ZIP untuk konversi VML->DrawingML.");
    }

    // Bagian docx yang mungkin memuat macro gambar: body utama surat, plus
    // header/footer jaga-jaga kalau template menaruh macro TTD di sana.
    $namaBagian = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nama = $zip->getNameIndex($i);
        if ($nama === 'word/document.xml' || preg_match('#^word/(header|footer)\d*\.xml$#', (string) $nama)) {
            $namaBagian[] = $nama;
        }
    }

    // Fingerprint blok VML yang PERSIS dibuat PhpWord TemplateProcessor::setImageValue().
    $polaVml = '/<w:pict><v:shape type="#_x0000_t75" style="width:([0-9.]+)px;height:([0-9.]+)px" stroked="f" filled="f"><v:imagedata r:id="(rId\d+)" o:title=""\/><\/v:shape><\/w:pict>/';

    $totalDikonversi = 0;
    $docPrId = 900000; // basis id besar & unik supaya tidak bentrok id docPr bawaan template

    foreach ($namaBagian as $nama) {
        $xml = $zip->getFromName($nama);
        if ($xml === false || strpos($xml, '<w:pict>') === false) {
            continue;
        }

        $jumlahDiBagianIni = 0;
        $xmlBaru = preg_replace_callback($polaVml, function ($m) use (&$docPrId) {
            $lebarPx = (float) $m[1];
            $tinggiPx = (float) $m[2];
            $rid = $m[3];

            // px (96 dpi, satuan yang dipakai PhpWord) -> EMU (satuan resmi
            // DrawingML). 1px = 9525 EMU.
            $cx = (int) round($lebarPx * 9525);
            $cy = (int) round($tinggiPx * 9525);
            $docPrId++;

            return '<w:drawing>'
                . '<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" distT="0" distB="0" distL="0" distR="0">'
                . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
                . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
                . '<wp:docPr id="' . $docPrId . '" name="TandaTangan' . $docPrId . '"/>'
                . '<wp:cNvGraphicFramePr>'
                . '<a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/>'
                . '</wp:cNvGraphicFramePr>'
                . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                . '<pic:nvPicPr>'
                . '<pic:cNvPr id="' . $docPrId . '" name="TandaTangan' . $docPrId . '"/>'
                . '<pic:cNvPicPr/>'
                . '</pic:nvPicPr>'
                . '<pic:blipFill>'
                . '<a:blip r:embed="' . $rid . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/>'
                . '<a:stretch><a:fillRect/></a:stretch>'
                . '</pic:blipFill>'
                . '<pic:spPr>'
                . '<a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
                . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
                . '</pic:spPr>'
                . '</pic:pic>'
                . '</a:graphicData>'
                . '</a:graphic>'
                . '</wp:inline>'
                . '</w:drawing>';
        }, $xml, -1, $jumlahDiBagianIni);

        if ($jumlahDiBagianIni > 0 && $xmlBaru !== null) {
            $zip->deleteName($nama);
            $zip->addFromString($nama, $xmlBaru);
            $totalDikonversi += $jumlahDiBagianIni;
        }
    }

    $zip->close();

    return $totalDikonversi;
}

function arp_tempel_ttd_ke_surat(PDO $conn, int $surat_id, int $direksi_id): bool
{
    try {
        $stmtSurat = $conn->prepare("SELECT drive_file_id FROM Surat WHERE id = :id");
        $stmtSurat->execute([':id' => $surat_id]);
        $driveFileId = $stmtSurat->fetchColumn();

        if (!$driveFileId) {
            arp_drive_set_last_error("Surat #{$surat_id} tidak memiliki drive_file_id (belum tersimpan di Drive).");
            return false;
        }

        $stmtUser = $conn->prepare("SELECT nama_lengkap, ttd_digital, jabatan_ttd FROM Users WHERE id = :id");
        $stmtUser->execute([':id' => $direksi_id]);
        $direksi = $stmtUser->fetch();

        if (!$direksi || empty($direksi['ttd_digital'])) {
            arp_drive_set_last_error("Direksi belum mengunggah tanda tangan digital (menu Profil > Tanda Tangan Digital).");
            return false;
        }

        $pathTtdLokal = realpath(__DIR__ . '/../' . ltrim($direksi['ttd_digital'], '/'));
        if (!$pathTtdLokal || !is_file($pathTtdLokal)) {
            arp_drive_set_last_error("File tanda tangan digital direksi tidak ditemukan di server: " . $direksi['ttd_digital']);
            return false;
        }

        // Unduh docx surat yang SUDAH ADA di Drive, tempel gambar TTD ke
        // macro ${ttd_direksi}, simpan ke path sementara BARU (bukan lewat
        // arp_dengan_template_sementara langsung, karena fungsi itu otomatis
        // menghapus file sementaranya sendiri sebelum sempat kita unggah balik).
        $pathHasilSementara = arp_dengan_template_sementara($driveFileId, function ($pathLokal) use ($pathTtdLokal, $direksi) {
            $processor = new TemplateProcessor($pathLokal);

            // Kalau macro ${ttd_direksi} tidak ada di template surat ini,
            // PhpWord tidak melempar error (macro yang tidak ditemukan
            // memang dilewati begitu saja oleh setImageValue) -- jadi tidak
            // perlu try/catch khusus di sini seperti setValue().
            $processor->setImageValue('ttd_direksi', [
                'path' => $pathTtdLokal,
                'width' => 130,
                'height' => 65,
                'ratio' => true,
            ]);

            // Opsional: kalau direksi mengisi jabatan khusus untuk TTD (mis.
            // "Direktur Utama"), timpa juga macro teks ${jabatan_penandatangan}
            // kalau ada, supaya konsisten dengan siapa yang benar-benar approve.
            if (!empty($direksi['jabatan_ttd'])) {
                try {
                    $processor->setValue('jabatan_penandatangan', htmlspecialchars((string) $direksi['jabatan_ttd'], ENT_QUOTES));
                } catch (\Throwable $e) {
                    // macro tidak ada di template ini, lewati saja
                }
            }
            try {
                $processor->setValue('nama_penandatangan', htmlspecialchars((string) $direksi['nama_lengkap'], ENT_QUOTES));
            } catch (\Throwable $e) {
                // macro tidak ada di template ini, lewati saja
            }

            $pathSementara = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'surat_ttd_' . uniqid() . '.docx';
            $processor->saveAs($pathSementara);

            // PERBAIKAN BUG: PhpWord::setImageValue() SELALU menyisipkan gambar
            // pakai format lama VML (<w:pict>). Microsoft Word masih baca VML
            // (demi kompatibilitas mundur), tapi Google Docs/Drive TIDAK bisa
            // me-render VML sama sekali -- makanya kalau surat yang sudah
            // "ditempel" TTD ini dibuka lewat Drive/Google Docs, macro
            // ${ttd_direksi} malah hilang jadi ruang kosong (nama & jabatan
            // tetap muncul karena itu teks biasa, bukan gambar). Kalau file
            // yang sama diunduh lalu dibuka di Microsoft Word asli, gambarnya
            // muncul normal -- itu sebabnya bug ini gampang lolos waktu testing
            // manual pakai Word tapi baru ketahuan pas dibuka lewat web/Drive.
            // Fungsi di bawah membuka lagi file .docx yang barusan disimpan,
            // lalu mengganti blok VML itu jadi DrawingML (format modern yang
            // didukung Word MAUPUN Google Docs), tanpa perlu mengubah relasi
            // gambar yang sudah dibuat PhpWord.
            try {
                arp_ganti_vml_ttd_ke_drawingml($pathSementara);
            } catch (\Throwable $e) {
                // Best-effort: kalau konversi gagal, biarkan file versi VML
                // tetap tersimpan (masih benar kalau dibuka di Microsoft Word,
                // cuma tidak tampil kalau dibuka lewat Google Docs/Drive).
                error_log('Gagal konversi TTD VML->DrawingML: ' . $e->getMessage());
            }

            return $pathSementara;
        });

        $berhasil = arp_timpa_konten_drive(
            $driveFileId,
            $pathHasilSementara,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        if (is_file($pathHasilSementara)) {
            @unlink($pathHasilSementara);
        }

        if (!$berhasil) {
            arp_drive_set_last_error('Gagal menimpa file surat di Drive dengan versi bertanda tangan: ' . arp_drive_last_error());
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        error_log('Gagal menempelkan TTD ke Surat #' . $surat_id . ': ' . $e->getMessage());
        arp_drive_set_last_error($e->getMessage());
        return false;
    }
}

// ==========================================
// AMBIL TEMPLATE + KODE_SURAT UNTUK MODUL REIMBURSE.
// Template "Reimbursement Harian" WAJIB sudah dihubungkan ke sebuah
// Kode_Surat lewat Kode_Template (Admin > Kelola Jenis Surat), supaya
// nomor surat bisa dibuat otomatis.
// ==========================================
function arp_muat_template_reimburse(PDO $pdo): ?array
{
    $stmt = $pdo->prepare("
        SELECT k.*, t.id AS template_id, t.nama AS nama_template,
               t.drive_file_id, t.drive_link, t.format, t.fields_json
        FROM Template_Master t
        JOIN Kode_Template kt ON kt.template_id = t.id
        JOIN Kode_Surat k ON k.id = kt.kode_id
        WHERE t.nama LIKE '%Reimburs%' AND t.format = 'word_pdf' AND t.drive_file_id IS NOT NULL
        ORDER BY t.id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

// ==========================================
// PROSES PENGAJUAN REIMBURSE: generate surat Word dari template,
// upload ke Drive, simpan baris Surat + Reimburse dalam SATU transaksi.
// Nominal (kolom Reimburse.nominal) = ${total_bayar} hasil hitung dari
// tabel rincian item (qty x harga), BUKAN input manual.
//
// $dataFormPost : nilai field dinamis (['tanggal_pengeluaran'=>'2026-09-01', ...])
//                 -- key yang namanya mengandung "tanggal"/"tgl" otomatis
//                 diformat ke tanggal Indonesia untuk isi dokumen.
// $itemsPost    : baris rincian pengeluaran, cth
//                 [['deskripsi'=>'Bensin dinas','qty'=>'1','harga_satuan'=>'150000']]
//
// @return array{ok:bool, msg:string, reimburse_id?:int, nomor?:string}
// ==========================================
function arp_proses_pengajuan_reimburse(PDO $pdo, array $kodeRow, int $userId, array $dataFormPost, array $itemsPost, ?string $noUrutManual = null): array
{
    // Bersihkan baris item kosong
    $items = [];
    foreach ($itemsPost as $baris) {
        $baris = array_map('trim', (array) $baris);
        $adaIsi = false;
        foreach ($baris as $v) {
            if ($v !== '') {
                $adaIsi = true;
                break;
            }
        }
        if ($adaIsi)
            $items[] = $baris;
    }
    if (empty($items)) {
        return ['ok' => false, 'msg' => 'Isi minimal satu baris rincian pengeluaran.'];
    }

    $ringkasanDisertakan = [
        'ppn' => false,
        'pph_23' => false,
        'diskon' => false,
        'grand_total' => false,
        'dp' => false,
        'total_bayar' => true,
        'sisa_pelunasan' => false,
    ];
    $hitung = hitungRingkasanTotalSurat($items, $ringkasanDisertakan);
    if (!$hitung['ada_subtotal'] || $hitung['total'] <= 0) {
        return ['ok' => false, 'msg' => 'Isi Qty & Harga pada rincian pengeluaran dengan benar (harus lebih dari 0).'];
    }
    $nominal = (float) $hitung['total'];

    $dataFormDocx = [];
    $dataFormMentah = [];
    foreach ($dataFormPost as $fieldName => $fieldValue) {
        $fieldValue = trim((string) $fieldValue);
        $dataFormMentah[$fieldName] = $fieldValue;
        if (preg_match('/tanggal|tgl/i', $fieldName) && $fieldValue !== '') {
            $fieldValue = formatTanggalIndonesia($fieldValue);
        }
        $dataFormDocx[$fieldName] = $fieldValue;
    }

    $tanggalPengeluaran = null;
    foreach ($dataFormMentah as $namaField => $nilai) {
        if ($tanggalPengeluaran === null && preg_match('/tanggal|tgl/i', $namaField) && $nilai !== '') {
            $tanggalPengeluaran = $nilai;
        }
    }
    $tanggalPengeluaran = $tanggalPengeluaran ?: date('Y-m-d');

    try {
        $pdo->beginTransaction();

        // GANTI: sekarang pakai resolveNomorSurat() supaya konsisten dengan
        // modul Surat -- nomor otomatis dihitung dari nomor tertinggi yang
        // BENAR-BENAR sudah dipakai (bukan cuma kolom counter), dan bisa
        // diisi manual lewat $noUrutManual (tetap divalidasi anti-duplikat).
        $nomorSurat = resolveNomorSurat($pdo, (int) $kodeRow['id'], $noUrutManual);

        $stmtUser = $pdo->prepare("SELECT nama_lengkap FROM Users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $namaUser = $stmtUser->fetchColumn() ?: '-';

        $fileHasilRelatif = arp_dengan_template_sementara($kodeRow['drive_file_id'], function ($pathTemplateLokal) use ($dataFormDocx, $items, $nomorSurat, $kodeRow, $ringkasanDisertakan, $namaUser) {
            return generateSuratDocx($pathTemplateLokal, $dataFormDocx, $items, $nomorSurat, [], $kodeRow['nama'], $namaUser, $ringkasanDisertakan);
        });

        $pathAbsolut = __DIR__ . '/../' . $fileHasilRelatif;

        $hasilDrive = arp_upload_ke_drive(
            $pathAbsolut,
            basename($fileHasilRelatif),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $userId,
            'Reimburse'
        );

        if (is_file($pathAbsolut)) {
            @unlink($pathAbsolut);
        }
        if (!$hasilDrive || empty($hasilDrive['link'])) {
            throw new RuntimeException('Gagal mengunggah surat ke Google Drive: ' . arp_drive_last_error());
        }

        $perihal = 'Pengajuan Reimbursement - ' . $namaUser;

        $insertSurat = $pdo->prepare("INSERT INTO Surat
        (nomor, kode_id, template_id, perihal, status, arah, tujuan, dibuat_oleh, tgl_dibuat, file_hasil, drive_file_id, drive_link, isi_data)
        VALUES (?, ?, ?, ?, 'Draft', 'Keluar', ?, ?, CURDATE(), ?, ?, ?, ?)");
        $insertSurat->execute([
            $nomorSurat,
            $kodeRow['id'],
            $kodeRow['template_id'],
            $perihal,
            $namaUser,
            $userId,
            $hasilDrive['link'],
            $hasilDrive['file_id'] ?? null,
            $hasilDrive['link'],
            json_encode(array_merge($dataFormMentah, ['__items' => $items]), JSON_UNESCAPED_UNICODE),
        ]);
        $suratId = (int) $pdo->lastInsertId();

        $insertReim = $pdo->prepare("INSERT INTO Reimburse
        (user_id, tanggal_pengeluaran, keterangan, nominal, lampiran_bukti, status, surat_id)
        VALUES (?, ?, ?, ?, ?, 'Menunggu', ?)");
        $insertReim->execute([
            $userId,
            $tanggalPengeluaran,
            $perihal,
            $nominal,
            $hasilDrive['link'],
            $suratId,
        ]);
        $reimburseId = (int) $pdo->lastInsertId();

        $pdo->commit();

        catatAudit(
            $pdo,
            'Reimburse',
            'Tambah',
            "Mengajukan reimburse sebesar Rp" . number_format($nominal, 0, ',', '.') . " (surat {$nomorSurat})",
            null,
            ['nominal' => $nominal, 'nomor_surat' => $nomorSurat]
        );

        return ['ok' => true, 'msg' => "Pengajuan reimbursement berhasil dikirim! Nomor surat: {$nomorSurat}.", 'reimburse_id' => $reimburseId, 'nomor' => $nomorSurat];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log('Gagal generate surat reimburse: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Gagal membuat surat: ' . $e->getMessage()];
    }
}