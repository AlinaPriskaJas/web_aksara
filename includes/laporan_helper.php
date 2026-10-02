<?php
// includes/laporan_helper.php
//
// Helper MANDIRI untuk modul "Laporan Pemeriksaan".
// Surat dan hanya dipakai sebagai REFERENSI pola (scan placeholder, cloneRow,
// penomoran, dll). Semua fungsi di sini berprefix lp_ supaya tidak pernah
// bentrok walau kedua file kebetulan ter-include di halaman yang sama.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/drive_helper.php';

use PhpOffice\PhpWord\TemplateProcessor;

if (!defined('LAPORAN_PEMERIKSAAN_DIR')) {
    define('LAPORAN_PEMERIKSAAN_DIR', __DIR__ . '/../storage/laporan_pemeriksaan/');
}

// Placeholder ${item_xxx} = kolom tabel berulang (cloneRow).
const LP_PREFIX_KOLOM_TABEL = 'item_';
const LP_ANCHOR_KOLOM_TABEL = 'item_no';

const LP_PREFIX_CEK = 'cek';
// Field yang diisi OTOMATIS sistem -> tidak boleh muncul sebagai input form.
// Sengaja jauh lebih pendek dari FIELD_OTOMATIS_SISTEM milik modul Surat
// (tidak ada ppn/pph/diskon/terbilang, karena laporan pemeriksaan bukan surat uang).
const LP_FIELD_OTOMATIS = ['nomor', 'nomor_laporan', 'item_no', 'item_sub_total'];

// Tambah dekat konstanta lain
const LP_PREFIX_HASIL = 'hasil';

// ===== PENGUJIAN DAN PENGUKURAN (ukurN / ukurN_M) — grup input manual =====
const LP_PREFIX_UKUR = 'ukur';

// ===== Pengukuran dan Pengujian Safety Device (sfdN / sfdN_ket) — grup input manual =====
const LP_PREFIX_SFD = 'sfd';

// ===== Tabel Pengujian/Pengukuran model baru (Mesin Packing, dst) =====
// pgkN_hasil / pgkN_ket   -> A. Pengujian dan Pengukuran
// safN_hasil / safN_ket   -> B. Pengukuran dan Pengujian Safety Device
// bisN_hasil / bisN_nab   -> C. Pengukuran Kebisingan
const LP_TABEL_UJI_DEF = [
    'pgk' => [
        'judul' => 'A. Pengujian dan Pengukuran',
        'kolom_label' => 'Komponen yang Diuji',
        'kolom_hasil' => 'Hasil',
        'kolom2_key' => 'ket',
        'kolom2_judul' => 'Keterangan',
        'kol2_default' => '',
        'kol2_kosong' => '',      // ket kosong tetap kosong
        'satuan_hasil' => '',
        'hint_hasil' => 'Contoh: 226 Lux / Baik / -',
        'hint_kol2' => '',
    ],
    'saf' => [
        'judul' => 'B. Pengukuran dan Pengujian Safety Device',
        'kolom_label' => 'Komponen yang Diuji',
        'kolom_hasil' => 'Hasil',
        'kolom2_key' => 'ket',
        'kolom2_judul' => 'Keterangan',
        'kol2_default' => '',
        'kol2_kosong' => '',
        'satuan_hasil' => '',
        'hint_hasil' => 'Contoh: Berfungsi / -',
        'hint_kol2' => 'Contoh: Memenuhi syarat',
    ],
    'bis' => [
        'judul' => 'C. Pengukuran Kebisingan',
        'kolom_label' => 'Pengukuran Kebisingan',
        'kolom_hasil' => 'Hasil',
        'kolom2_key' => 'nab',
        'kolom2_judul' => 'Nilai Ambang Batas (dBA)',
        'kol2_kosong' => '-',
        'satuan_hasil' => 'dB',    // angka murni -> "76.7 dB"
        'hint_hasil' => 'Contoh: 76.7 (dB otomatis)',
        'hint_kol2' => '',
    ],
];

// ===== C. Pengukuran Tegangan (Electrical Panel Test) — grup checkbox tunggal =====
const LP_TEG_ITEM = [
    'volt' => 'Volt',
    'ampere' => 'Ampere',
    'hz' => 'Hz',
    'cosq' => 'Cos Q',
    'isolasi' => 'Insulation',
    'grounding' => 'Grounding',
];
const LP_POLA_TEG = '/^teg_(volt|ampere|hz|cosq|isolasi|grounding)$/i';

// ===== C. Pengukuran Tegangan — tabel HASIL/RESULT =====
const LP_HASIL_UKUR_GRUP = [
    'Daya' => [
        'hsl_daya' => 'Daya (kVA)',
    ],
    'Voltage & Resistance Measurement Grounding' => [
        'hsl_v_rs' => 'R-S (Volt)',
        'hsl_v_rt' => 'R-T (Volt)',
        'hsl_v_st' => 'S-T (Volt)',
        'hsl_v_rn' => 'R-N (Volt)',
        'hsl_v_rg' => 'R-G (Volt)',
        'hsl_grounding' => 'G (Ohm)',
    ],
    'Cos Q, Frekuensi & Insulating' => [
        'hsl_cosq' => 'Cos Q',
        'hsl_freq' => 'Freq (Hz)',
        'hsl_insulating' => 'Insulating (MΩ)',
    ],
    'Electrical Current Measurement' => [
        'hsl_i_r' => 'R (Amp)',
        'hsl_i_s' => 'S (Amp)',
        'hsl_i_t' => 'T (Amp)',
        'hsl_i_n' => 'N (Amp)',
    ],
];

// ===== D. PENGUKURAN TEGANGAN (BARU) — grup input, tampilan sama seperti HASIL/RESULT =====
const LP_VTG_GRUP = [
    'Voltage & Resistance Measurement Grounding' => [
        'vtg_rs' => 'R-S (Volt)',
        'vtg_rt' => 'R-T (Volt)',
        'vtg_st' => 'S-T (Volt)',
        'vtg_rn' => 'R-N (Volt)',
        'vtg_rg' => 'R-G (Volt)',
        'vtg_g' => 'G (Ohm)',
    ],
    'Cos Q, Frekuensi & Insulating' => [
        'vtg_cosq' => 'Cos Q',
        'vtg_freq' => 'Freq (Hz)',
        'vtg_insulating' => 'Insulating (MΩ)',
    ],
    'Electrical Current Measurement' => [
        'vtg_ir' => 'R (Amp)',
        'vtg_is' => 'S (Amp)',
        'vtg_it' => 'T (Amp)',
        'vtg_in' => 'N (Amp)',
    ],
];

/** Daftar datar semua field D. Pengukuran Tegangan. */
function lp_vtg_semua_field(): array
{
    $semua = [];
    foreach (LP_VTG_GRUP as $grup) {
        $semua = array_merge($semua, array_keys($grup));
    }
    return $semua;
}

/** Kosong -> "-". Nilai lain (0.8, <5, dst) dibiarkan apa adanya. */
function lp_terapkan_vtg_kosong(array $data): array
{
    foreach (lp_vtg_semua_field() as $f) {
        if (array_key_exists($f, $data) && trim((string) $data[$f]) === '') {
            $data[$f] = '-';
        }
    }
    return $data;
}

/** Daftar datar semua nama field tabel HASIL/RESULT. */
function lp_hasil_ukur_semua_field(): array
{
    $semua = [];
    foreach (LP_HASIL_UKUR_GRUP as $grup) {
        $semua = array_merge($semua, array_keys($grup));
    }
    return $semua;
}

// Tambahkan dekat konstanta LP_PREFIX_CEK lainnya
const LP_POLA_CEK_KET_GABUNGAN = '/^cek(\d+)_(\d+)_ket$/i';

// ===== Perhitungan Arus Nominal (In) — grup kalkulasi otomatis =====
const LP_ARUS_FIELD_OTOMATIS = ['arus_daya_watt', 'arus_kha', 'arus_in', 'kha_pengantar_utama'];   // <<< TAMBAHKAN kha_pengantar_utama

// ===== Perhitungan Pembatas Arus / Rating Proteksi Utama — grup otomatis =====
const LP_PROTEKSI_FIELD_OTOMATIS = ['proteksi_rating_cb', 'proteksi_kesimpulan', 'proteksi_status'];   // <<< tambah proteksi_status

// ===== Perhitungan Keseimbangan Beban RST — grup kalkulasi otomatis =====
const LP_RST_FIELD_OTOMATIS = [
    'rst_jumlah_arus',
    'rst_arus_rata_rata',
    'rst_a',
    'rst_b',
    'rst_c',
    'rst_selisih_a',
    'rst_selisih_b',
    'rst_selisih_c',
    'rst_jumlah_selisih',
    'rst_unbalance',
    'rst_simbol',
    'rst_status',
];
const LP_RST_BATAS_UNBALANCE = 20.0; // ambang batas persen untuk ACC

// ===== Jenis dan Ukuran Kabel — grup kalkulasi otomatis =====
const LP_KABEL_FIELD_OTOMATIS = [
    'kabel_jumlah_inti',
    'kabel_kha_rumus',
    'kabel_kha_total',
    'kabel_simbol',
    'kabel_status',
];

// ===== Pengujian yang digunakan — grup kalkulasi otomatis =====
const LP_PENGUJIAN_FIELD_OTOMATIS = [
    'pengujian_isolasi_catatan',
    'pengujian_pembumian_catatan',
    /* 'pengujian_suhu_catatan', */
];

// Teks tetap per item, dipetakan dari key checkbox -> kalimat yang dimasukkan
// kalau item tsb dicentang. Tambah/ubah baris di sini kalau ada item lain.
const LP_PENGUJIAN_TEKS = [
    'isolasi' => '(tidak dapat dilakukan karena instalasi tidak dapat di berhentikan)',
    'pembumian' => '(tidak dapat dilakukan karena instalasi tidak dapat di berhentikan)',
    /* 'suhu'      => '(setelah di cek tidak ada suhu komponen yang melebihi standar)', */
];

// ===== Data Umum Bejana Tekan =====

// Nilai default yang langsung terisi di form (tetap bisa diubah operator)
const LP_DEFAULT_FIELD = [
    'standar_yang_dipakai' => 'Permenaker No. 37 tahun 2016',
    'riwayat_motor_diesel' => 'Berkala',
    // --- Data Teknis ---
    'md_tenaga_mula' => 'Accu',
    'gen_putaran' => '1500',
    'pnd_massa_jenis' => '2400',
    'pnd_koefisien' => '0.11',
    'kapasitas_kerja' => '5000',
    'kapasitas_bucket' => '3',
    'wl_rem_service' => 'Caliper Disc Brake/ Air Over Hydraulic',
    'wl_rem_parking' => 'Flexible Shaft Control/ Mechanical',

    // --- Pesawat Angkat / Wheel Loader ---
    'tenaga_penggerak' => 'Motor Bakar',
    'izin_pakai' => 'Berkala',
    'data_riwayat' => '-',

    // <<< BARU: Analisa Komponen (Dump Truck) — akm_
    'akm_jumlah_torak' => '1',
    'akm_massa_jenis' => '1800',
];

// ===== Pelaksana Pemeriksaan (dropdown nama ahli K3) =====
const LP_PILIHAN_PELAKSANA = [
    'Rama Regawa Sri Anggayana',
    'Imam Taufiq Rakhman Hidayat',
    'Fauzan Akmaluddin',
    'Fakhziar Wildan Hidayat',
];

// ===== Izin Pemakaian (dropdown) =====
const LP_PILIHAN_IZIN_PAKAI = [
    'Pertama',
    'Berkala',
    'Ulang',
    'Khusus'
];

// ===== Klasifikasi (dropdown) =====
const LP_PILIHAN_KLASIFIKASI = [
    'Stationary/ Tetap'
]; // nanti tinggal tambah opsi lain di array ini

// Field yang kalau dikosongkan otomatis tercetak "-" di Word
const LP_FIELD_STRIP_JIKA_KOSONG = [
    'no_pengesahan',
    'nama_operator',
    'model_type',
    'tempat_pembuatan',
    'nama_juru_las',
    'sertifikasi_standar',
    'izin_pemakaian',
    'no_seri_unit_mesin',
    'no_lisensi_operator',   // <<< TAMBAHKAN
];

// Hint di dalam input (placeholder HTML)
const LP_PLACEHOLDER_FIELD = [
    'penanggung_jawab' => 'Kosongkan = sama dengan Perusahaan Pemakai',
    'kapasitas_daya' => 'Contoh: 705 (satuan kW otomatis)',
    'nama_juru_las' => 'Kosongkan jika tidak ada (otomatis "-")',
    // --- Data Teknis ---
    'md_jumlah_silinder' => 'Contoh: 6 (Buah otomatis)',
    'gen_frekuensi' => 'Contoh: 50 (Hz otomatis)',
    'gen_putaran' => 'Contoh: 1500 (rpm otomatis)',
    'gen_tegangan' => 'Contoh: 400 (volt otomatis)',
    'hsl_daya' => 'Contoh: 940 (kVA otomatis)',
    'hsl_v_rs' => 'Contoh: 400',
    'hsl_grounding' => 'Contoh: <5',
    'kapasitas_kerja' => 'Contoh: 5000 (Kg otomatis)',
    'kapasitas_bucket' => 'Contoh: 3 (m³ otomatis)',
    'tinggi_angkat' => 'Contoh: 3100 (mm otomatis)',
    'kapasitas_keterangan' => 'Contoh: 100 ml – 9500 botol/jam (tekan Enter untuk baris baru)',

    'kapasitas_spesifikasi' => 'Contoh: 24 (m³ otomatis)',
    'kapasitas_pengujian' => 'Contoh: 20 (m³ otomatis)',
    'tinggi_angkat_bak' => 'Contoh: 3,8 (Meter otomatis)',
    'no_seri_unit_kendaraan' => 'Contoh: LZGJLDR42RX060514',

    // <<< BARU
    'akm_diameter_torak' => 'Contoh: 14,7',
    'akm_tekanan' => 'Contoh: 254,929',
    'akm_jumlah_torak' => 'Contoh: 1',
    'akm_massa_jenis' => 'Contoh: 1800',
    'akm_kapasitas_spesifikasi' => 'Contoh: 24 (m³ otomatis)',

    'thk_material' => 'Contoh: Baja Carbon',
    'thk_tekanan_desain' => 'Contoh: 12 (dipakai khusus utk Thickness Test)',

    'kmp_kapasitas_bucket' => 'Contoh: 3',
    'kmp_tekanan_mpa' => 'Contoh: 18',
    'kmp_diameter_torak' => 'Contoh: 5.1',
];

// Angka murni -> otomatis ditambah satuan
const LP_FIELD_SATUAN_OTOMATIS = [
    'kapasitas_daya' => 'kW',
    // --- Data Teknis ---
    'md_jumlah_silinder' => 'Buah',
    'gen_frekuensi' => 'Hz',
    'gen_putaran' => 'rpm',
    'gen_tegangan' => 'volt',
    'hsl_daya' => 'kVA',
    'kapasitas_kerja' => 'Kg',
    'kapasitas_bucket' => 'm³',
    'tinggi_angkat' => 'mm',
    // --- Data Teknis Wheel Loader ---
    'wl_kapasitas_kerja' => 'kg',
    'wl_berat_kendaraan' => 'kg',
    'wl_panjang' => 'mm',
    'wl_tinggi' => 'mm',
    'wl_lebar' => 'mm',
    'wl_kapasitas_bucket' => 'm³',
    'wl_jarak_roda' => 'mm',
    'wl_kecepatan_maju' => 'km/jam',
    'wl_kecepatan_mundur' => 'km/jam',
    'wl_breakout_force' => 'kN',
    'wl_radius_ban' => 'mm',
    'wl_radius_bucket' => 'mm',
    'wl_pompa_tekanan' => 'MPa',

    'kapasitas_spesifikasi' => 'm³',
    'kapasitas_pengujian' => 'm³',
    'tinggi_angkat_bak' => 'Meter',

    'akm_kapasitas_spesifikasi' => 'm³',
];

// ===== Checklist Pemeriksaan (data teknis bejana) — grup input manual =====
// ===== Checklist Pemeriksaan (data teknis bejana) — grup input manual =====
const LP_CHK_BEJANA_GRUP = [
    'Shell / Badan' => [
        'shell_jumlah_roundshell' => 'Jumlah Roundshell',
        'shell_cara_penyambungan' => 'Cara Penyambungan',
        'shell_material' => 'Material / Bahan',
        'shell_diameter_dalam' => 'Diameter Dalam (ID) (mm)',
        'shell_diameter_luar' => 'Diameter Luar (OD) (mm)',   // template lama
        'shell_ketebalan' => 'Ketebalan (t) (mm)',
        'shell_tinggi' => 'Tinggi (mm)',
        'shell_panjang' => 'Panjang / Tinggi Badan (mm)',
    ],
    'Penguat' => [
        'penguat_jenis' => 'Jenis',
        'penguat_jumlah' => 'Jumlah',
        'penguat_ukuran' => 'Ukuran',
    ],
    'Tutup Head — Depan / Atas' => [
        'head_depan_jenis' => 'Jenis / Bentuk',
        'head_depan_lengkungan' => 'Lengkungan',
        'head_depan_kemiringan' => 'Kemiringan',
        'head_depan_diameter' => 'Diameter (mm)',
        'head_depan_ketebalan' => 'Ketebalan (mm)',
        'head_depan_material' => 'Material / Bahan',
    ],
    'Tutup Head — Belakang / Bawah' => [
        'head_belakang_jenis' => 'Jenis / Bentuk',
        'head_belakang_lengkungan' => 'Lengkungan',
        'head_belakang_kemiringan' => 'Kemiringan',
        'head_belakang_diameter' => 'Diameter (mm)',
        'head_belakang_ketebalan' => 'Ketebalan (mm)',
        'head_belakang_material' => 'Material / Bahan',
    ],
    'Instalasi Pipa' => [
        'pipa_diameter' => 'Diameter (mm)',
        'pipa_ketebalan' => 'Ketebalan (mm)',
        'pipa_jenis' => 'Jenis Pipa',
        'pipa_jumlah' => 'Jumlah',
        // template lama
        'pipa_keluaran_diameter' => 'Diameter Keluaran',
        'pipa_masukan_diameter' => 'Diameter Masukan',
    ],
];

// Field yang satuannya "mm" ditambahkan OTOMATIS bila isinya hanya angka.
// (Isi "8 x 50" atau "Pipa air" tidak disentuh.)
const LP_CHK_BEJANA_SATUAN_MM = [
    'shell_diameter_dalam',
    'shell_diameter_luar',
    'shell_ketebalan',
    'shell_tinggi',
    'shell_panjang',
    'head_depan_diameter',
    'head_depan_ketebalan',
    'head_belakang_diameter',
    'head_belakang_ketebalan',
    'pipa_diameter',
    'pipa_ketebalan',
];

// ===== Pemeriksaan Visual (kelompok A-D, sub a,b,c...) =====
const LP_POLA_VISUAL = '/^vis([A-Z])_([a-z]+)_(ok|tdk|ket)$/';

// ===== Pemeriksaan Dimensi (dim1_a, dim1_a_ket, dst) =====
const LP_POLA_DIMENSI = '/^dim(\d+)_((?!ket$)[a-z]+)(_ket)?$/';
// Satuan otomatis untuk input yang HANYA angka. Kosongkan ('') untuk mematikan.
const LP_DIMENSI_UNIT_OTOMATIS = 'mm';

// ===== Pemeriksaan Visual KETEL UAP (kv1_1_baik, kv1_1_buruk, kv1_1_ket) =====
const LP_POLA_KETEL_VISUAL = '/^kv(\d+)_(\d+)_(baik|buruk|ket)$/i';

// ===== PEMERIKSAAN VISUAL & FUNGSI (vf1_1_ok, vf1_1_tdk, vf1_1_ket) =====
const LP_POLA_VF = '/^vf(\d+)_(\d+)_(ok|tdk|ket)$/i';

// ===== DATA CHECKLIST PEMERIKSAAN: dcp1_baik, dcp9_a_baik, dcp9_a_ket =====
const LP_POLA_DCP = '/^dcp(\d+)(?:_([a-z]+))?_(baik|buruk|ket)$/i';                       // isi awal kolom keterangan di form
const LP_POLA_FIELD_MULTILINE = '/^dcp\d+(?:_[a-z]+)?_ket$/i'; // keterangan boleh multi-baris

// Keterangan otomatis bila dikosongkan operator. Isi '' untuk mematikan.
const LP_VF_KET_DEFAULT_OK = 'Baik';
const LP_VF_KET_DEFAULT_KOSONG = 'Tidak ditemukan';

// ===== PEMERIKSAAN VISUAL & FUNGSI (VERSI BARU — diletakkan SETELAH "Data Teknis") =====
// Struktur SAMA seperti tabel "vf" (Grup > Lokasi > Komponen > Item), TAPI prefix
// SENGAJA dibedakan ("pvf", bukan "vf") supaya tidak bentrok kalau dalam SATU file
// Word yang sama masih ada tabel "vf" versi lama di bagian lain. Field lama "vf..."
// TIDAK diubah sama sekali oleh kode di bawah ini.
const LP_POLA_PVF = '/^pvf(\d+)_(\d+)_(ok|tdk|ket)$/i';
const LP_PVF_KET_DEFAULT_OK = 'Baik';
const LP_PVF_KET_DEFAULT_KOSONG = 'Tidak ditemukan';


// ===== PEMERIKSAAN TIDAK MERUSAK (NDT) — baris dinamis + 2 foto per baris =====
const LP_ANCHOR_NDT = 'ndt_no';
const LP_NDT_FIELD_TEKS = ['ndt_bagian', 'ndt_lokasi', 'ndt_ada', 'ndt_tidak', 'ndt_ket'];
const LP_NDT_FIELD_FOTO = ['ndt_foto1', 'ndt_foto2'];
// dipakai di pemeriksaan.php untuk exclude dari input generik
const LP_NDT_FIELD_SEMUA = ['ndt_jenis', 'ndt_no', 'ndt_bagian', 'ndt_lokasi', 'ndt_ada', 'ndt_tidak', 'ndt_ket', 'ndt_foto1', 'ndt_foto2'];

/**
 * Ambil baris NDT dari POST + $_FILES, buang baris yang benar-benar kosong.
 * $inputNdt : $_POST['ndt']       = [i => ['bagian'=>..,'lokasi'=>..,'cacat'=>'ada'|'tidak','ket'=>..]]
 * $fileNdt  : $_FILES['ndt_foto'] = ['name'=>[i=>['foto1'=>..,'foto2'=>..]], 'tmp_name'=>[...], 'error'=>[...]]
 */
function lp_siapkan_baris_ndt(array $inputNdt, ?array $fileNdt): array
{
    $rows = [];
    $no = 0;
    foreach ($inputNdt as $idx => $baris) {
        $bagian = trim((string) ($baris['bagian'] ?? ''));
        $lokasi = trim((string) ($baris['lokasi'] ?? ''));
        $ket = trim((string) ($baris['ket'] ?? ''));
        $cacat = $baris['cacat'] ?? ''; // 'ada' | 'tidak' | ''

        $foto1Err = $fileNdt['error'][$idx]['foto1'] ?? UPLOAD_ERR_NO_FILE;
        $foto2Err = $fileNdt['error'][$idx]['foto2'] ?? UPLOAD_ERR_NO_FILE;
        $foto1Tmp = $fileNdt['tmp_name'][$idx]['foto1'] ?? null;
        $foto2Tmp = $fileNdt['tmp_name'][$idx]['foto2'] ?? null;

        $adaIsi = $bagian !== '' || $lokasi !== '' || $ket !== '' || $cacat !== ''
            || $foto1Err === UPLOAD_ERR_OK || $foto2Err === UPLOAD_ERR_OK;
        if (!$adaIsi) {
            continue;
        }

        $no++;
        // Kalau salah satu (Ada/Tidak Ada) sudah dicentang, pasangannya dikosongkan
// (bukan "-"). "-" hanya dipakai kalau keduanya benar-benar tidak dicentang.
        if ($cacat === 'ada') {
            $ndtAda = '√';
            $ndtTidak = '';
        } elseif ($cacat === 'tidak') {
            $ndtAda = '';
            $ndtTidak = '√';
        } else {
            $ndtAda = '-';
            $ndtTidak = '-';
        }

        $rows[] = [
            'bagian' => $bagian !== '' ? $bagian : '-',
            'lokasi' => $lokasi !== '' ? $lokasi : '-',
            'ada' => $ndtAda,
            'tidak' => $ndtTidak,
            'ket' => $ket !== '' ? $ket : '-',
            'foto1_path' => $foto1Err === UPLOAD_ERR_OK ? $foto1Tmp : null,
            'foto2_path' => $foto2Err === UPLOAD_ERR_OK ? $foto2Tmp : null,
        ];
    }
    return $rows;
}

/**
 * Klon blok "baris data NDT" + "baris foto NDT" (persis di bawahnya)
 * sebanyak $count kali, dengan pola penomoran #1, #2, ... (sama seperti
 * cloneRow bawaan PhpWord), supaya setValue('ndt_xxx#N', ...) & 
 * setImageValue('ndt_fotoN#N', ...) bisa langsung dipakai.
 */
function lp_clone_blok_baris_ndt(string $xml, int $count): string
{
    $posAnchor = strpos($xml, '${' . LP_ANCHOR_NDT . '}');
    if ($posAnchor === false) {
        return $xml;
    }

    if (!preg_match_all('/<w:tr\b/', substr($xml, 0, $posAnchor), $mAwal, PREG_OFFSET_CAPTURE)) {
        return $xml;
    }
    $mulaiBlok = end($mAwal[0])[1];

    $akhirTagAnchor = strpos($xml, '</w:tr>', $posAnchor);
    if ($akhirTagAnchor === false) {
        return $xml;
    }
    $akhirBlok = $akhirTagAnchor + strlen('</w:tr>');

    // ikutkan baris foto persis di bawahnya kalau ada ${ndt_foto1}/${ndt_foto2}
    if (preg_match('/\G<w:tr\b/', $xml, $m, 0, $akhirBlok)) {
        $akhirTagBerikut = strpos($xml, '</w:tr>', $akhirBlok);
        if ($akhirTagBerikut !== false) {
            $akhirBarisBerikut = $akhirTagBerikut + strlen('</w:tr>');
            $teksBarisBerikut = substr($xml, $akhirBlok, $akhirBarisBerikut - $akhirBlok);
            if (
                strpos($teksBarisBerikut, '${ndt_foto1}') !== false
                || strpos($teksBarisBerikut, '${ndt_foto2}') !== false
            ) {
                $akhirBlok = $akhirBarisBerikut;
            }
        }
    }

    $blokAsli = substr($xml, $mulaiBlok, $akhirBlok - $mulaiBlok);

    $hasil = '';
    for ($i = 1; $i <= max(1, $count); $i++) {
        $hasil .= preg_replace_callback(
            '/\$\{(ndt_[a-zA-Z0-9_]+)\}/',
            fn($m) => '${' . $m[1] . '#' . $i . '}',
            $blokAsli
        );
    }
    return substr($xml, 0, $mulaiBlok) . $hasil . substr($xml, $akhirBlok);
}

// ===== V. PENGUJIAN (Tinggi Angkat/Beban/Kecepatan/Gerakan/Hasil/Ket) =====
// BERBEDA dan TERPISAH dari "Pengujian yang digunakan" (pengujian_isolasi_catatan
// dst) dan dari LP_TABEL_UJI_DEF (pgk/saf/bis). Prefix "puj_" khusus tabel ini.
// Baris dinamis (bisa Tambah Baris), 1 baris per item di Word.
const LP_ANCHOR_PUJ = 'puj_tinggi_angkat';
const LP_PUJ_FIELD_SEMUA = ['puj_tinggi_angkat', 'puj_beban', 'puj_kecepatan', 'puj_gerakan', 'puj_hasil', 'puj_ket'];

/** Huruf a, b, c, ... untuk penomoran sub-gerakan (indeks 0-based). */
function lp_huruf_urut(int $idx): string
{
    return chr(97 + ($idx % 26));
}

/**
 * Gabung sub-gerakan jadi satu teks siap-tampil.
 * 1 item saja -> teks polos TANPA huruf. >1 item -> tiap baris diberi "a. ", "b. ", dst.
 */
function lp_gabung_gerakan(array $subGerakan): string
{
    $sub = array_values(array_filter(array_map('trim', $subGerakan), fn($v) => $v !== ''));
    if (empty($sub)) {
        return '-';
    }
    if (count($sub) === 1) {
        return $sub[0];
    }
    $baris = [];
    foreach ($sub as $i => $teks) {
        $baris[] = lp_huruf_urut($i) . '. ' . $teks;
    }
    return implode("\n", $baris);
}

/**
 * Ket mode "Hitung Penurunan": teks "Tidak ada penurunan pengukuran" TETAP,
 * angkanya diambil dari Tinggi Angkat (baris ybs) dikurangi Ukur Akhir (input manual).
 * Contoh hasil: "Tidak ada penurunan pengukuran\n989 – 989 = 0 mm"
 */
function lp_hitung_ket_penurunan(string $tinggiAngkatMentah, string $ukurAkhirMentah): string
{
    $tinggi = lp_ke_angka(preg_replace('/[^\d.,\-]/', '', $tinggiAngkatMentah));
    $ukur = lp_ke_angka(preg_replace('/[^\d.,\-]/', '', $ukurAkhirMentah));

    if ($tinggi === null || $ukur === null) {
        return 'Tidak ada penurunan pengukuran';
    }

    $selisih = $tinggi - $ukur;
    $fmt = fn($n) => (floor($n) == $n) ? number_format($n, 0, ',', '') : lp_format_angka_koma($n, 2);

    return 'Tidak ada penurunan pengukuran' . "\n" . $fmt($tinggi) . ' – ' . $fmt($ukur) . ' = ' . $fmt($selisih) . ' mm';
}

/**
 * Siapkan baris V. PENGUJIAN dari $_POST['puj'], buang baris yang benar-benar kosong.
 * $inputPuj[i] = ['tinggi_angkat','beban','kecepatan','gerakan'=>[...], 'hasil',
 *                 'ket_mode'=>'manual'|'hitung','ket_manual','ukur_akhir']
 */
function lp_siapkan_baris_puj(array $inputPuj): array
{
    $rows = [];
    foreach ($inputPuj as $baris) {
        $tinggi = trim((string) ($baris['tinggi_angkat'] ?? ''));
        $beban = trim((string) ($baris['beban'] ?? ''));
        $kecepatan = trim((string) ($baris['kecepatan'] ?? ''));
        $gerakanArr = (array) ($baris['gerakan'] ?? []);
        $hasil = trim((string) ($baris['hasil'] ?? ''));
        $ketMode = ($baris['ket_mode'] ?? 'manual') === 'hitung' ? 'hitung' : 'manual';
        $ketManual = trim((string) ($baris['ket_manual'] ?? ''));
        $ukurAkhir = trim((string) ($baris['ukur_akhir'] ?? ''));

        $gerakanBersih = array_values(array_filter(array_map('trim', $gerakanArr), fn($v) => $v !== ''));

        $adaIsi = $tinggi !== '' || $beban !== '' || $kecepatan !== '' || $gerakanBersih
            || $hasil !== '' || $ketManual !== '' || $ukurAkhir !== '';
        if (!$adaIsi) {
            continue;
        }

        $ket = $ketMode === 'hitung'
            ? lp_hitung_ket_penurunan($tinggi, $ukurAkhir)
            : ($ketManual !== '' ? $ketManual : '-');

        $rows[] = [
            'tinggi_angkat' => $tinggi !== '' ? $tinggi : '-',
            'beban' => $beban !== '' ? $beban : '-',
            'kecepatan' => $kecepatan !== '' ? $kecepatan : '-',
            'gerakan' => lp_gabung_gerakan($gerakanBersih),
            'hasil' => $hasil !== '' ? $hasil : '-',
            'ket' => $ket,
        ];
    }
    return $rows;
}

// ===== PENGUJIAN (model tabel No/Fungsi/Tinggi/Kecepatan/Gerakan/Beban/Hasil/Ket) — prefix pjn_ =====
// TERPISAH dari puj_ (V. PENGUJIAN lama).
const LP_ANCHOR_PJN = 'pjn_no';
const LP_PJN_FIELD_SEMUA = [
    'pjn_no',
    'pjn_fungsi',
    'pjn_tinggi_angkat',
    'pjn_kecepatan',
    'pjn_gerakan',
    'pjn_beban',
    'pjn_hasil',
    'pjn_ket',
];
const LP_PJN_SATUAN_PENURUNAN = 'mm';   // kosongkan ('') kalau tidak mau ada satuan setelah selisih

/**
 * Ket mode "Hitung": "Tidak Terjadi Penurunan" + baris "711-708=3".
 * 711 diambil dari kolom Tinggi Angkat (baris yang sama), 708 dari input Ukur Akhir.
 */
function lp_hitung_ket_pjn(string $tinggiMentah, string $ukurAkhirMentah): string
{
    $teks = 'Tidak Terjadi Penurunan';
    $tinggi = lp_ke_angka(preg_replace('/[^\d.,\-]/', '', $tinggiMentah));
    $ukur = lp_ke_angka(preg_replace('/[^\d.,\-]/', '', $ukurAkhirMentah));
    if ($tinggi === null || $ukur === null) {
        return $teks;
    }
    $fmt = fn($n) => (floor($n) == $n) ? number_format($n, 0, ',', '') : lp_format_angka_koma($n, 2);
    $selisih = $tinggi - $ukur;
    return $teks . "\n" . $fmt($tinggi) . '-' . $fmt($ukur) . '=' . $fmt($selisih)
        . (LP_PJN_SATUAN_PENURUNAN !== '' ? ' ' . LP_PJN_SATUAN_PENURUNAN : '');
}

/** Siapkan baris PENGUJIAN dari $_POST['pjn']; baris yang benar-benar kosong dibuang. */
function lp_siapkan_baris_pjn(array $inputPjn): array
{
    $rows = [];
    foreach ($inputPjn as $b) {
        $fungsi = trim((string) ($b['fungsi'] ?? ''));
        $tinggi = trim((string) ($b['tinggi_angkat'] ?? ''));
        $kec = trim((string) ($b['kecepatan'] ?? ''));
        $gerakan = trim((string) ($b['gerakan'] ?? ''));
        $beban = trim((string) ($b['beban'] ?? ''));
        $hasil = trim((string) ($b['hasil'] ?? ''));
        $mode = ($b['ket_mode'] ?? 'manual') === 'hitung' ? 'hitung' : 'manual';
        $ketManual = trim((string) ($b['ket_manual'] ?? ''));
        $ukurAkhir = trim((string) ($b['ukur_akhir'] ?? ''));

        // "hasil" sengaja tidak dihitung: default-nya "Baik", jadi baris kosong tidak ikut tercetak
        if (
            $fungsi === '' && $tinggi === '' && $kec === '' && $gerakan === ''
            && $beban === '' && $ketManual === '' && $ukurAkhir === ''
        ) {
            continue;
        }

        $ket = $mode === 'hitung'
            ? lp_hitung_ket_pjn($tinggi, $ukurAkhir)
            : ($ketManual !== '' ? $ketManual : '-');

        $rows[] = [
            'fungsi' => $fungsi !== '' ? $fungsi : '-',
            'tinggi_angkat' => $tinggi !== '' ? $tinggi : '-',
            'kecepatan' => $kec !== '' ? $kec : '-',
            'gerakan' => $gerakan !== '' ? $gerakan : '-',
            'beban' => $beban !== '' ? $beban : '-',
            'hasil' => $hasil !== '' ? $hasil : '-',
            'ket' => $ket,
        ];
    }
    return $rows;
}

/**
 * Klon SATU baris <w:tr> yang memuat ${$anchorMacro} sebanyak $count kali
 * (penomoran #1,#2,... sama seperti cloneRow bawaan). Dipakai untuk tabel
 * dinamis 1-baris-per-item, mis. "V. PENGUJIAN" (puj_) — beda dari
 * lp_clone_blok_baris_ndt() yang mengikutkan baris foto tambahan.
 */
function lp_clone_blok_satu_baris(string $xml, string $anchorMacro, int $count): string
{
    $posAnchor = strpos($xml, '${' . $anchorMacro . '}');
    if ($posAnchor === false) {
        return $xml;
    }
    if (!preg_match_all('/<w:tr\b/', substr($xml, 0, $posAnchor), $mAwal, PREG_OFFSET_CAPTURE)) {
        return $xml;
    }
    $mulaiBlok = end($mAwal[0])[1];

    $akhirTagAnchor = strpos($xml, '</w:tr>', $posAnchor);
    if ($akhirTagAnchor === false) {
        return $xml;
    }
    $akhirBlok = $akhirTagAnchor + strlen('</w:tr>');
    $blokAsli = substr($xml, $mulaiBlok, $akhirBlok - $mulaiBlok);

    $hasil = '';
    for ($i = 1; $i <= max(1, $count); $i++) {
        $hasil .= preg_replace_callback(
            '/\$\{([a-zA-Z0-9_]+)\}/',
            fn($m) => '${' . $m[1] . '#' . $i . '}',
            $blokAsli
        );
    }
    return substr($xml, 0, $mulaiBlok) . $hasil . substr($xml, $akhirBlok);
}


/**
 * Baca judul grup, lokasi, komponen, dan label pemeriksaan dari tabel Word
 * untuk pola PVF (${pvfN_M_ok}/_tdk/_ket) — tabel "PEMERIKSAAN VISUAL & FUNGSI"
 * versi BARU yang diletakkan setelah bagian "Data Teknis".
 * Return: ['grup' => [1 => 'Pemeriksaan dengan Mesin Mati'],
 *          'item' => ['1_1' => ['lokasi'=>'Kerangka Utama / Chasis','komponen'=>'Rangka Penguat','label'=>'...']]]
 */
function lp_scan_label_pvf_docx(string $path): array
{
    $hasil = ['grup' => [], 'item' => []];
    $xml = lp_baca_xml_docx($path);
    if ($xml === '' || !preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return $hasil;
    }

    $polos = function (string $x): string {
        $x = preg_replace('/<\/w:p>|<w:br\b[^>]*\/>/', ' ', $x);
        $x = html_entity_decode(preg_replace('/<[^>]+>/', '', $x), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $x));
    };

    $lokasi = '';
    $komponen = '';
    $judulTerakhir = null;

    foreach ($barisList[0] as $xmlBaris) {
        if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
            continue;
        }

        $sel = [];
        $col = 0;
        foreach ($selList[0] as $xmlSel) {
            $span = preg_match('/<w:gridSpan\b[^>]*w:val="(\d+)"/', $xmlSel, $g) ? max(1, (int) $g[1]) : 1;
            $vm = null;
            if (preg_match('/<w:vMerge\b([^>]*)>/', $xmlSel, $v)) {
                $vm = strpos($v[1], 'restart') !== false ? 'restart' : 'lanjut';
            }
            $sel[] = ['col' => $col, 'span' => $span, 'vm' => $vm, 'teks' => $polos($xmlSel)];
            $col += $span;
        }
        $teksBaris = implode(' ', array_column($sel, 'teks'));

        // --- Baris item: mengandung ${pvfN_M_ok} / _tdk ---
        if (preg_match('/pvf(\d+)_(\d+)_(?:ok|tdk)/i', $teksBaris, $m)) {
            $no = (int) $m[1];
            $urut = (int) $m[2];

            if (!isset($hasil['grup'][$no]) && $judulTerakhir !== null) {
                $hasil['grup'][$no] = $judulTerakhir;
            }

            $label = '';
            foreach ($sel as $s) {
                if ($s['col'] > 2 || strpos($s['teks'], '{') !== false) {
                    continue;
                }
                $ada = $s['teks'] !== '' && $s['vm'] !== 'lanjut';
                if ($s['col'] === 0) {
                    if ($ada)
                        $lokasi = $s['teks'];
                } elseif ($s['col'] === 1 && $s['span'] >= 2) {
                    // Komponen & Pemeriksaan digabung dalam 1 sel
                    $komponen = '';
                    $label = $s['teks'];
                } elseif ($s['col'] === 1) {
                    if ($ada)
                        $komponen = $s['teks'];
                } elseif ($s['col'] === 2) {
                    $label = $s['teks'];
                }
            }

            $hasil['item'][$no . '_' . $urut] = [
                'lokasi' => $lokasi,
                'komponen' => $komponen,
                'label' => $label,
            ];
            continue;
        }

        // --- Baris judul grup: tanpa placeholder, teks awal "1. ...." ---
        if (
            strpos($teksBaris, '{') === false
            && preg_match('/^(\d+)\s*\.\s*(.+)$/su', $sel[0]['teks'] ?? '', $m)
        ) {
            $judulTerakhir = trim($m[2]);
            $lokasi = '';
            $komponen = '';
        }
    }
    return $hasil;
}

/** Kelompokkan field pvfN_M_ok/tdk/ket jadi struktur grup -> item (tabel PVF baru). */
function lp_kelompokkan_pvf(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tmp = [];
    $sisa = [];
    foreach ($fields as $f) {
        if (preg_match(LP_POLA_PVF, $f, $m)) {
            $tmp[(int) $m[1]][(int) $m[2]][strtolower($m[3])] = $f;
        } else {
            $sisa[] = $f;
        }
    }
    ksort($tmp);

    $hasil = [];
    foreach ($tmp as $no => $subs) {
        ksort($subs);
        $items = [];
        foreach ($subs as $urut => $g) {
            if (empty($g['ok']) || empty($g['tdk'])) { // pasangan tidak lengkap -> balik ke field biasa
                foreach ($g as $f) {
                    $sisa[] = $f;
                }
                continue;
            }
            $kunci = $no . '_' . $urut;
            $d = $labelDariDocx['item'][$kunci] ?? $labelLama['item'][$kunci] ?? [];
            $items[] = [
                'key' => 'pvf' . $no . '_' . $urut,
                'urut' => $urut,
                'field_ok' => $g['ok'],
                'field_tdk' => $g['tdk'],
                'field_ket' => $g['ket'] ?? null,
                'lokasi' => $d['lokasi'] ?? '',
                'komponen' => $d['komponen'] ?? '',
                'label' => !empty($d['label']) ? $d['label'] : ('Item ' . $no . '.' . $urut),
            ];
        }
        if ($items) {
            $hasil[] = [
                'no' => $no,
                'judul' => $labelDariDocx['grup'][$no] ?? $labelLama['grup'][$no] ?? ('Kelompok ' . $no),
                'items' => $items,
            ];
        }
    }
    return ['pvf' => $hasil, 'fields' => $sisa];
}

/**
 * Radio status -> nilai placeholder Word (tabel PVF baru).
 *  ok     : Memenuhi "√"  | Tidak ""
 *  tdk    : Memenuhi ""   | Tidak "√"
 *  kosong : "-" | "-"
 */
function lp_expand_pvf_ke_field(array $def, array $inputStatus, array $inputKet): array
{
    $hasil = [];
    foreach ($def as $grup) {
        foreach ($grup['items'] as $it) {
            $st = $inputStatus[$it['key']] ?? '';
            if ($st === 'ok') {
                $hasil[$it['field_ok']] = '√';
                $hasil[$it['field_tdk']] = '';
            } elseif ($st === 'tdk') {
                $hasil[$it['field_ok']] = '';
                $hasil[$it['field_tdk']] = '√';
            } else {
                $hasil[$it['field_ok']] = '-';
                $hasil[$it['field_tdk']] = '-';
            }
            if (!empty($it['field_ket'])) {
                $hasil[$it['field_ket']] = trim((string) ($inputKet[$it['key']] ?? ''));
            }
        }
    }
    return $hasil;
}

/**
 * Hitung rowspan kolom Lokasi & Komponen untuk tabel PEMERIKSAAN VISUAL &
 * FUNGSI (pvf), supaya tampilannya sama seperti tabel asli di Word (Lokasi
 * & Komponen sebagai KOLOM dengan rowspan, bukan baris terpisah).
 * Return sejajar dengan $items: ['lokasi_rowspan'=>int|null, 'komponen_rowspan'=>int|null]
 * null = baris kelanjutan, selnya TIDAK dicetak sama sekali.
 */
function lp_pvf_hitung_rowspan(array $items): array
{
    $n = count($items);
    $peta = array_fill(0, $n, ['lokasi_rowspan' => null, 'komponen_rowspan' => null]);

    $i = 0;
    while ($i < $n) {
        $lok = $items[$i]['lokasi'] ?? '';
        $j = $i;
        while ($j < $n && ($items[$j]['lokasi'] ?? '') === $lok)
            $j++;
        $peta[$i]['lokasi_rowspan'] = $j - $i;

        $k = $i;
        while ($k < $j) {
            $kom = $items[$k]['komponen'] ?? '';
            $l = $k;
            while ($l < $j && ($items[$l]['komponen'] ?? '') === $kom)
                $l++;
            $peta[$k]['komponen_rowspan'] = $l - $k;
            $k = $l;
        }
        $i = $j;
    }
    return $peta;
}

// ===== DATA CHECKLIST PEMERIKSAAN (Baik/Buruk): ckA_1_baik, ckA_1_buruk, ckA_1_ket =====
const LP_POLA_CK = '/^ck([A-Z])_(\d+)_(baik|buruk|ket)$/';

// ===== Perhitungan Thickness Test — grup kalkulasi otomatis =====
const LP_THK_INPUT_FIELDS = ['thk_material', 'thk_ca', 'thk_efisiensi', 'thk_jari_jari', 'thk_diameter', 'thk_kapasitas', 'thk_stress', 'thk_tekanan_desain'];
const LP_THK_FIELD_OTOMATIS = [
    'thk_tebal_head',
    'thk_tebal_shell',
    'thk_head_pembilang',
    'thk_head_penyebut',
    'thk_head_t_hitung',
    'thk_head_simbol',
    'thk_head_status',
    'thk_head_kesimpulan',
    'thk_head_jenis_teks',
    'thk_head_rumus_pembilang',
    'thk_head_rumus_penyebut',
    'thk_shell_pembilang',
    'thk_shell_penyebut',
    'thk_shell_t_hitung',
    'thk_shell_simbol',
    'thk_shell_status',
    'thk_shell_kesimpulan',
];
// Bentuk head untuk rumus: 'ellipsoidal' (2:1) | 'torispherical' | 'hemispherical'
const LP_THK_JENIS_HEAD = 'ellipsoidal';

// ===== Field BERPASANGAN: 2 input sejajar di form -> 1 placeholder di Word =====
// key = nama placeholder di Word. Hasil di Word: "kiri/kanan".
const LP_FIELD_PASANGAN = [
    'model_type' => ['kiri' => 'Model', 'kanan' => 'Type'],
    'no_seri_unit' => ['kiri' => 'No. Serie', 'kanan' => 'No. Unit'],
    'tempat_tahun_pembuatan' => ['kiri' => 'Tempat Pembuatan', 'kanan' => 'Tahun Pembuatan'],

    // --- Motor Diesel: hasil "Perkins/ 4006-23TGA3A" ---
    'merek_tipe' => [
        'kiri' => 'Merek',
        'kanan' => 'Tipe',
        'pemisah' => '/ ',
    ],
    // --- hasil "England/ 2015" ---
    'lokasi_tahun_pembuatan' => [
        'kiri' => 'Lokasi',
        'kanan' => 'Tahun Pembuatan',
        'pemisah' => '/ ',
    ],

    // --- Data Teknis: Generator ---
    'gen_merek_tipe' => [
        'kiri' => 'Merek',
        'kanan' => 'Tipe',
        'pemisah' => '/ ',
    ],
    'gen_pabrik_negara' => [
        'kiri' => 'Pabrik Pembuat',
        'kanan' => 'Negara',
        'pemisah' => '/ ',
    ],
    'gen_daya' => [
        'kiri' => 'Daya (kW)',
        'kanan' => 'Daya (kVA)',
        'pemisah' => '/ ',
        'satuan_kiri' => 'kW',
        'satuan_kanan' => 'kVA',
    ],
    'merk_type' => [
        'label' => 'Merk / Type',
        'kiri' => 'Merk',
        'kanan' => 'Type',
        'pemisah' => '/ ',
    ],
    'tempat_tahun_buat' => [
        'label' => 'Tempat/Tahun Pembuatan',
        'kiri' => 'Tempat',
        'kanan' => 'Tahun',
        'pemisah' => '/ ',
    ],
    // hasil "162 kW @ 2200 rpm"
    'wl_daya_bersih' => [
        'label' => 'Daya Bersih',
        'kiri' => 'Daya (kW)',
        'kanan' => 'Putaran (rpm)',
        'pemisah' => ' @ ',
        'satuan_kiri' => 'kW',
        'satuan_kanan' => 'rpm',
    ],
    // hasil "Weichai/ 2024"
    'wl_merek_tahun' => [
        'label' => 'Merek / Tahun Pembuatan',
        'kiri' => 'Merek',
        'kanan' => 'Tahun',
        'pemisah' => '/ ',
    ],

    'merk_model' => [
        'kiri' => 'Merk',
        'kanan' => 'Model',
        'pemisah' => '/ ',
    ],

    'dtr_merek_tipe' => [
        'kiri' => 'Merk',
        'kanan' => 'Tipe',
        'pemisah' => '/ ',
    ],
];
const LP_PASANGAN_PEMISAH = '/';   // ganti ' / ' kalau mau ada spasi

// ===== DATA TEKNIS: urutan & label tampilan di form (nomor mengikuti urutan array) =====
const LP_DATA_TEKNIS_GRUP = [
    'A. MOTOR DIESEL' => [
        'md_tenaga_mula' => '7. Tenaga Mula',
        'md_jumlah_silinder' => '8. Jumlah Silinder',
    ],
    'B. GENERATOR' => [
        'gen_merek_tipe' => '1. Merek / Tipe',
        'gen_pabrik_negara' => '2. Pabrik Pembuat / Negara',
        'gen_tahun_pembuatan' => '3. Tahun Pembuatan',
        'gen_nomor_seri' => '4. Nomor Seri',
        'gen_daya' => '5. Daya',
        'gen_frekuensi' => '6. Frekuensi',
        'gen_putaran' => '7. Putaran',
        'gen_tegangan' => '8. Tegangan',
        'gen_faktor_daya' => '9. Faktor Daya (φ)',
    ],
];

// Field turunan (diisi otomatis dari lokasi_tahun_pembuatan, tidak tampil di form)
const LP_DATA_TEKNIS_FIELD_OTOMATIS = ['md_negara', 'md_tahun_pembuatan'];

// ===== ANALISIS: Perhitungan Pondasi =====
const LP_PND_INPUT_FIELDS = [
    'pnd_berat_mesin',
    'pnd_massa_jenis',
    'pnd_koefisien',
    'pnd_panjang',
    'pnd_lebar',
    'pnd_tinggi',
];
const LP_PND_FIELD_OTOMATIS = [
    'pnd_putaran',
    'pnd_w_lbs',
    'pnd_w_ton',
    'pnd_volume',
    'pnd_massa_jenis_ton',
    'pnd_w_aktual',
    'pnd_simbol',
    'pnd_kesimpulan',
    'pnd_status',
];
const LP_PND_KONVERSI_LBS_KE_TON = 2000; // short ton
const LP_PND_POTONG_DESIMAL = true;      // true = dipotong (sesuai contoh), false = dibulatkan

/** Daftar datar semua nama field Data Teknis. */
function lp_data_teknis_semua_field(): array
{
    $semua = [];
    foreach (LP_DATA_TEKNIS_GRUP as $grup) {
        $semua = array_merge($semua, array_keys($grup));
    }
    return $semua;
}

// ===== ANALISIS: Analisa Komponen (Sistem Hidrolik - Wheel Loader) =====
const LP_KMP_INPUT_FIELDS = ['kmp_kapasitas_bucket', 'kmp_diameter_torak', 'kmp_tekanan_mpa'];
const LP_KMP_FIELD_OTOMATIS = [
    'kmp_volume',
    'kmp_massa_jenis',
    'kmp_swl_kg',
    'kmp_swl_ton',
    'kmp_tekanan_kgcm2',
    'kmp_jumlah_torak',
    'kmp_luas',
    'kmp_q_kg',
    'kmp_q_ton',
    'kmp_simbol',
    'kmp_status',
];
const LP_KMP_JUMLAH_TORAK = 2;           // nilai tetap (n)
const LP_KMP_MASSA_JENIS = 1700;         // nilai tetap (kg/m³, batu split)
const LP_KMP_MPA_KE_KGCM2 = 10.1971621;  // 1 MPa = 10,1971621 kg/cm²
const LP_KMP_PI_PER_4 = 0.785;           // sesuai contoh Word

// ===== ANALISIS: Analisa Komponen (Dump Truck / Kendaraan Angkut) =====
// BERBEDA dan TERPISAH dari LP_KMP_* (Analisa Komponen Wheel Loader, prefix
// "kmp_") yang sudah ada. Prefix "akm_" supaya tidak pernah bentrok, dan
// keduanya BISA aktif bersamaan di template berbeda tanpa saling ganggu.
const LP_AKM_INPUT_FIELDS = ['akm_kapasitas_spesifikasi', 'akm_diameter_torak', 'akm_tekanan', 'akm_jumlah_torak', 'akm_massa_jenis'];
const LP_AKM_FIELD_OTOMATIS = [
    'akm_volume',
    'akm_kapasitas_kg',
    'akm_kapasitas_ton',
    'akm_luas',
    'akm_q_kg',
    'akm_q_ton',
    'akm_simbol',
    'akm_status',
];

// ===== Field yang isinya boleh multi-baris (Enter di textarea -> baris baru di Word) =====
const LP_FIELD_MULTILINE = ['kapasitas_keterangan'];

/** Ubah teks multi-baris jadi XML Word yang aman (escape per baris + <w:br/>). */
function lp_nilai_multiline_ke_xml(string $nilai): string
{
    $baris = preg_split('/\r\n|\r|\n/', trim($nilai));
    $baris = array_map(fn($b) => htmlspecialchars($b, ENT_QUOTES), $baris);
    return implode('</w:t><w:br/><w:t xml:space="preserve">', $baris);
}

/**
 * Analisa Komponen sistem hidrolik (Wheel Loader).
 *  SWL = v x rho           [kg]  -> /1000 = ton
 *  P   = MPa x 10,1971621  [kg/cm2]
 *  A   = 0,785 x D^2       [cm2]
 *  Q   = A x P x n         [kg]  -> /1000 = ton
 * ACC jika SWL <= Q.
 * v dari kapasitas_bucket (cadangan: wl_kapasitas_bucket), P dari wl_pompa_tekanan (MPa).
 * Hasil dipotong (bukan dibulatkan) supaya sama dengan contoh di Word.
 */
function lp_hitung_komponen_hidrolik(array $d): array
{
    $hasil = array_fill_keys(LP_KMP_FIELD_OTOMATIS, '');

    // Input manual khusus Analisa Komponen (TIDAK lagi ambil dari Data Teknis)
    $v = lp_pnd_angka_desimal((string) ($d['kmp_kapasitas_bucket'] ?? ''));
    $mpa = lp_pnd_angka_desimal((string) ($d['kmp_tekanan_mpa'] ?? ''));
    $dia = lp_pnd_angka_desimal((string) ($d['kmp_diameter_torak'] ?? ''));

    $n = LP_KMP_JUMLAH_TORAK;
    $rho = LP_KMP_MASSA_JENIS;

    // --- SWL ---
    $swlKg = null;
    if ($v > 0) {
        $swlKg = $v * $rho;
        $hasil['kmp_volume'] = lp_pnd_fmt($v, 2, false);
        $hasil['kmp_swl_kg'] = lp_pnd_fmt($swlKg, 2, false);
        $hasil['kmp_swl_ton'] = lp_pnd_fmt(lp_pnd_potong($swlKg / 1000, 1), 1);
    }

    // --- Tekanan MPa -> kg/cm2 ---
    $p = null;
    if ($mpa > 0) {
        $p = lp_pnd_potong($mpa * LP_KMP_MPA_KE_KGCM2);
        $hasil['kmp_tekanan_kgcm2'] = lp_pnd_fmt($p);
    }

    // --- Luas torak ---
    $a = null;
    if ($dia > 0) {
        $a = lp_pnd_potong(LP_KMP_PI_PER_4 * $dia * $dia);
        $hasil['kmp_luas'] = lp_pnd_fmt($a);
    }

    // --- Kekuatan angkat (pakai A & P yang sudah dipotong, sama seperti tampilan Word) ---
    $qKg = null;
    if ($a !== null && $p !== null) {
        $qKg = lp_pnd_potong($a * $p * $n, 0);
        $hasil['kmp_q_kg'] = lp_pnd_fmt($qKg, 0);
        $hasil['kmp_q_ton'] = lp_pnd_fmt(lp_pnd_potong($qKg / 1000, 1), 1);
    }

    // --- Kesimpulan ---
    if ($swlKg !== null && $qKg !== null) {
        $hasil['kmp_simbol'] = $swlKg < $qKg ? '<' : ($swlKg > $qKg ? '>' : '=');
        $hasil['kmp_status'] = $swlKg <= $qKg ? 'ACC' : 'Belum ACC';
    }

    return $hasil;
}

/**
 * Perhitungan "ANALISIS - A. Analisa Komponen" (versi Dump Truck / Kendaraan
 * Angkut). BERBEDA dan TERPISAH dari lp_hitung_komponen_hidrolik() (Analisa
 * Komponen Wheel Loader, prefix "kmp_") yang sudah ada -- keduanya aman
 * dipakai bersamaan di template berbeda karena prefix field beda ("akm_").
 *
 * Posisi di Word: SETELAH bagian "PEMERIKSAAN VISUAL & FUNGSI".
 *
 *  Kapasitas (SWL)     = v x rho          [kg] -> /1000 = ton
 *  A (Luas Torak)      = 0.785 x D^2      [cm2]
 *  Q (Kekuatan Angkat) = A x P x n        [kg] -> /1000 = ton
 * ACC jika Kapasitas <= Q.
 *
 * v (Kapasitas Spesifikasi) diambil OTOMATIS dari field umum yang sudah
 * ada, ${kapasitas_spesifikasi}. D, P, n, rho semuanya INPUT MANUAL
 * (n & rho sudah terisi nilai default 1 & 1800 di form via LP_DEFAULT_FIELD,
 * tapi tetap bisa diubah operator).
 */
function lp_hitung_analisa_komponen(array $d): array
{
    $hasil = array_fill_keys(LP_AKM_FIELD_OTOMATIS, '');

    $v = lp_pnd_angka_desimal((string) ($d['akm_kapasitas_spesifikasi'] ?? ''));
    $dia = lp_pnd_angka_desimal((string) ($d['akm_diameter_torak'] ?? ''));
    $p = lp_pnd_angka_desimal((string) ($d['akm_tekanan'] ?? ''));
    $nMentah = lp_pnd_angka_desimal((string) ($d['akm_jumlah_torak'] ?? ''));
    $rhoMentah = lp_pnd_angka_bulat((string) ($d['akm_massa_jenis'] ?? ''));

    $n = ($nMentah !== null && $nMentah > 0) ? $nMentah : 1.0;
    $rho = ($rhoMentah !== null && $rhoMentah > 0) ? $rhoMentah : 1800.0;

    // --- Kapasitas (SWL) ---
    $kapasitasKg = null;
    if ($v !== null && $v > 0) {
        $kapasitasKg = $v * $rho;
        $hasil['akm_volume'] = lp_format_angka_koma($v, 2);
        $hasil['akm_kapasitas_kg'] = number_format(lp_pnd_potong($kapasitasKg, 0), 0, ',', '');
        $hasil['akm_kapasitas_ton'] = lp_format_angka_koma(lp_pnd_potong($kapasitasKg / 1000, 2), 2);
    }

    // --- Luas Torak (A) ---
    $a = null;
    if ($dia !== null && $dia > 0) {
        $a = lp_pnd_potong(LP_KMP_PI_PER_4 * $dia * $dia, 2);
        $hasil['akm_luas'] = lp_format_angka_koma($a, 2);
    }

    // --- Kekuatan Angkat (Q) ---
    $qKg = null;
    if ($a !== null && $p !== null && $p > 0) {
        $qKg = lp_pnd_potong($a * $p * $n, 0);
        $hasil['akm_q_kg'] = number_format($qKg, 0, ',', '');
        $hasil['akm_q_ton'] = lp_format_angka_koma(lp_pnd_potong($qKg / 1000, 3), 3);
    }

    // --- Kesimpulan ---
    if ($kapasitasKg !== null && $qKg !== null) {
        $hasil['akm_simbol'] = $kapasitasKg < $qKg ? '<' : ($kapasitasKg > $qKg ? '>' : '=');
        $hasil['akm_status'] = $kapasitasKg <= $qKg ? 'ACC' : 'Belum ACC';
    }

    return $hasil;
}


// ===== DATA TEKNIS (bentuk TABEL, mengikuti tampilan Word) — Wheel Loader =====
// Baris bisa punya 'sub' (mis. Rem -> Service/Parking) => label induk di-rowspan.
const LP_DATA_TEKNIS_TABEL = [
    'Spesifikasi Loader' => [
        ['field' => 'wl_kapasitas_kerja', 'label' => 'Kapasitas / Bobot Kerja', 'hint' => 'Contoh: 5000 (kg otomatis)'],
        ['field' => 'wl_berat_kendaraan', 'label' => 'Berat Kendaraan', 'hint' => 'Contoh: 16900 (kg otomatis)'],
        ['field' => 'wl_panjang', 'label' => 'Panjang Keseluruhan', 'hint' => 'Contoh: 7970 (mm otomatis)'],
        ['field' => 'wl_tinggi', 'label' => 'Tinggi Keseluruhan', 'hint' => 'Contoh: 3515 (mm otomatis)'],
        ['field' => 'wl_lebar', 'label' => 'Lebar Keseluruhan', 'hint' => 'Contoh: 2850 (mm otomatis)'],
        ['field' => 'wl_kapasitas_bucket', 'label' => 'Kapasitas Bucket', 'hint' => 'Contoh: 3 (m³ otomatis)'],
        ['field' => 'wl_jarak_roda', 'label' => 'Jarak roda', 'hint' => 'Contoh: 2250 (mm otomatis)'],
        ['field' => 'wl_ukuran_ban', 'label' => 'Ukuran lebar Roda (Tire)', 'hint' => 'Contoh: 23.5-25'],
        ['field' => 'wl_kecepatan_maju', 'label' => 'Kecepatan maksimum (Travelling)', 'hint' => 'Contoh: 38 (km/jam otomatis)'],
        ['field' => 'wl_kecepatan_mundur', 'label' => 'Kecepatan mundur', 'hint' => 'Contoh: 18 (km/jam otomatis)'],
        ['field' => 'wl_breakout_force', 'label' => 'Bucket Breakout Force', 'hint' => 'Contoh: 170 (kN otomatis)'],
        [
            'label' => 'Rem',
            'sub' => [
                ['field' => 'wl_rem_service', 'label' => 'Service', 'hint' => ''],
                ['field' => 'wl_rem_parking', 'label' => 'Parking', 'hint' => ''],
            ]
        ],
        [
            'label' => 'Radius Putaran',
            'sub' => [
                ['field' => 'wl_radius_ban', 'label' => 'Outside of Tire', 'hint' => 'Contoh: 5950 (mm otomatis)'],
                ['field' => 'wl_radius_bucket', 'label' => 'Bucket Carry', 'hint' => 'Contoh: 6970 (mm otomatis)'],
            ]
        ],
    ],
    'Mesin' => [
        ['field' => 'wl_model_type', 'label' => 'Model / Type', 'hint' => 'Contoh: WD10G220E21'],
        ['field' => 'wl_nomor_seri', 'label' => 'Nomor seri', 'hint' => ''],
        ['field' => 'wl_jumlah_silinder', 'label' => 'Jumlah silinder', 'hint' => 'Contoh: 4'],
        ['field' => 'wl_daya_bersih', 'label' => 'Daya Bersih', 'hint' => ''],  // pasangan
        ['field' => 'wl_merek_tahun', 'label' => 'Merek / Tahun pembuatan', 'hint' => ''],  // pasangan
        ['field' => 'wl_pabrik_pembuat', 'label' => 'Pabrik pembuat', 'hint' => 'Contoh: Weichai Power Co., Ltd'],
    ],
    'Pompa Hidrolik' => [
        ['field' => 'wl_pompa_type', 'label' => 'Type', 'hint' => ''],
        ['field' => 'wl_pompa_tekanan', 'label' => 'Tekanan', 'hint' => 'Contoh: 18 (MPa otomatis)'],
    ],
];

// ===== II. DATA TEKNIS (Dump Truck / Kendaraan Angkut) — TABEL BARU =====
// TERPISAH dari LP_DATA_TEKNIS_TABEL (Wheel Loader) dan LP_DTK_TABEL (Data
// Teknik Mesin Packing). Semua field prefix dtr_ supaya tidak pernah bentrok.
const LP_DTR_TABEL = [
    'Spesifikasi' => [
        ['field' => 'dtr_no_seri', 'label' => 'No. Seri / Serial Number', 'hint' => ''],
        ['field' => 'dtr_no_registrasi', 'label' => 'No. Registrasi Kendaraan', 'hint' => ''],
        ['field' => 'dtr_no_rangka', 'label' => 'No. Rangka Kendaraan', 'hint' => ''],
        ['field' => 'dtr_no_uji', 'label' => 'No. Uji Kendaraan', 'hint' => 'Kosongkan = otomatis "-"'],
        ['field' => 'dtr_kapasitas_dump', 'label' => 'Kapasitas / Volume Dump', 'hint' => 'Contoh: 20 (m³ otomatis)'],
        ['field' => 'dtr_jbkb', 'label' => 'JBKB (Jumlah Berat Kombinasi yang Diperbolehkan)', 'hint' => 'Contoh: 25000 (Kg otomatis)'],
        ['field' => 'dtr_jbki', 'label' => 'JBKI (Jumlah Berat Kombinasi yang Diijinkan)', 'hint' => 'Contoh: 41500 (Kg otomatis)'],
        ['field' => 'dtr_berat_kosong', 'label' => 'Berat Kosong Kendaraan', 'hint' => 'Contoh: 25 (Ton otomatis)'],
        ['field' => 'dtr_bahan_bakar', 'label' => 'Bahan Bakar', 'hint' => 'Contoh: Solar'],
        ['field' => 'dtr_tangki_bahan_bakar', 'label' => 'Tangki Bahan Bakar', 'hint' => 'Contoh: Persegi'],
        ['field' => 'dtr_perlengkapan', 'label' => 'Perlengkapan / Attachment', 'hint' => 'Boleh dikosongkan'],
        [
            'label' => 'Kecepatan (Speed)',
            'sub' => [
                ['field' => 'dtr_kecepatan_angkat', 'label' => 'Angkat / Lifting (Loaded/Unloaded)', 'hint' => 'Contoh: 0,25 (m/s otomatis)'],
                ['field' => 'dtr_kecepatan_jalan', 'label' => 'Jalan / Travelling', 'hint' => 'Contoh: 77 (km/jam otomatis)'],
            ],
        ],
    ],
    'Penggerak Utama (Engine)' => [
        ['field' => 'dtr_merek_tipe', 'label' => 'Merk / Tipe', 'hint' => ''],
        ['field' => 'dtr_tahun_pembuatan', 'label' => 'Tahun Pembuatan', 'hint' => 'Contoh: 2024'],
        ['field' => 'dtr_eng_no', 'label' => 'Eng. No', 'hint' => ''],
        ['field' => 'dtr_putaran', 'label' => 'Putaran', 'hint' => 'Contoh: 2200 (rpm otomatis)'],
        ['field' => 'dtr_rated_output', 'label' => 'Rated Output', 'hint' => 'Contoh: 250 (Kw otomatis)'],
        ['field' => 'dtr_isi_silinder', 'label' => 'Isi Silinder', 'hint' => 'Contoh: 6 (Buah otomatis)'],
    ],
    'Dimensi (Dimension)' => [
        ['field' => 'dtr_panjang', 'label' => 'Panjang / Length', 'hint' => 'Contoh: 7654 (mm otomatis)'],
        ['field' => 'dtr_lebar', 'label' => 'Lebar / Width', 'hint' => 'Contoh: 3325 (mm otomatis)'],
        ['field' => 'dtr_tinggi', 'label' => 'Tinggi / High', 'hint' => 'Contoh: 2530 (mm otomatis)'],
        ['field' => 'dtr_julur_depan', 'label' => 'Julur Depan / Front Overhang', 'hint' => 'Kosongkan = otomatis "-"'],
        ['field' => 'dtr_julur_belakang', 'label' => 'Julur Belakang / Rear Overhang', 'hint' => 'Kosongkan = otomatis "-"'],
        ['field' => 'dtr_tinggi_angkat_dump', 'label' => 'Tinggi Angkat Dump / Bak Muatan', 'hint' => 'Contoh: 3,8 (m otomatis)'],
        ['field' => 'dtr_dump_bak_muatan', 'label' => 'Dump / Bak Muatan (p x l x t) mm', 'hint' => 'Contoh: 5812 x 2277 x 1825'],
    ],
    'Ban Depan' => [
        ['field' => 'dtr_ban_depan_ukuran', 'label' => 'Ukuran / Size', 'hint' => 'Contoh: 12,00 R20'],
        ['field' => 'dtr_ban_depan_tekanan', 'label' => 'Tekanan', 'hint' => 'Boleh dikosongkan'],
    ],
    'Ban Belakang' => [
        ['field' => 'dtr_ban_belakang_ukuran', 'label' => 'Ukuran / Size', 'hint' => 'Contoh: 12,00 R20'],
        ['field' => 'dtr_ban_belakang_tekanan', 'label' => 'Tekanan', 'hint' => 'Boleh dikosongkan'],
    ],
    'Rem' => [
        ['field' => 'dtr_rem_tipe', 'label' => 'Tipe', 'hint' => 'Contoh: Rem tromol ABS'],
    ],
    'Pompa Hidraulik' => [
        ['field' => 'dtr_pompa_tekanan', 'label' => 'Tekanan', 'hint' => 'Contoh: 25 (Mpa otomatis)'],
    ],
];

// Satuan otomatis: berlaku HANYA jika isian murni angka.
const LP_DTR_SATUAN = [
    'dtr_kapasitas_dump' => 'm³',
    'dtr_jbkb' => 'Kg',
    'dtr_jbki' => 'Kg',
    'dtr_berat_kosong' => 'Ton',
    'dtr_kecepatan_angkat' => 'm/s',
    'dtr_kecepatan_jalan' => 'km/jam',
    'dtr_putaran' => 'rpm',
    'dtr_rated_output' => 'Kw',
    'dtr_isi_silinder' => 'Buah',
    'dtr_panjang' => 'mm',
    'dtr_lebar' => 'mm',
    'dtr_tinggi' => 'mm',
    'dtr_tinggi_angkat_dump' => 'm',
    'dtr_pompa_tekanan' => 'Mpa',
];

/** Daftar datar semua field tabel Data Teknis Dump Truck / Kendaraan Angkut. */
function lp_dtr_semua_field(): array
{
    $semua = [];
    foreach (LP_DTR_TABEL as $barisGrup) {
        foreach ($barisGrup as $b) {
            if (!empty($b['sub'])) {
                foreach ($b['sub'] as $s) {
                    $semua[] = $s['field'];
                }
            } else {
                $semua[] = $b['field'];
            }
        }
    }
    return $semua;
}

/** Satuan otomatis (angka murni) + kosong -> "-" untuk field Data Teknis dtr_*. */
function lp_terapkan_satuan_dtr(array $data): array
{
    foreach (LP_DTR_SATUAN as $field => $satuan) {
        if (!isset($data[$field])) {
            continue;
        }
        $v = trim((string) $data[$field]);
        if ($v !== '' && preg_match('/^[\d.,]+$/', $v)) {
            $data[$field] = $v . ' ' . $satuan;
        }
    }
    foreach (lp_dtr_semua_field() as $f) {
        if (array_key_exists($f, $data) && trim((string) $data[$f]) === '') {
            $data[$f] = '-';
        }
    }
    return $data;
}

/**
 * HTML tabel input Data Teknis (Dump Truck / Kendaraan Angkut), tampilannya
 * mengikuti tabel di Word (rowspan per grup: Spesifikasi, Penggerak Utama,
 * Dimensi, Ban Depan, Ban Belakang, Rem, Pompa Hidraulik).
 * Memakai ULANG helper generik lp_dt_baris_aktif() & lp_dt_input_html()
 * yang sudah ada (jadi otomatis mendukung field pasangan seperti dtr_merek_tipe).
 */
function lp_dtr_render_tabel(array $nilaiDinamis, array $nilaiPasangan): string
{
    $h = '<div class="table-responsive-custom"><table class="table-custom" style="margin:0;"><tbody>';

    foreach (LP_DTR_TABEL as $judulGrup => $barisGrup) {
        $rows = lp_dt_baris_aktif($barisGrup, $nilaiDinamis);
        if (!$rows) {
            continue;
        }
        $total = 0;
        foreach ($rows as $b) {
            $total += !empty($b['sub']) ? count($b['sub']) : 1;
        }
        $selGrup = '<td rowspan="' . $total . '" class="lp-dt-grup" style="width:170px;">' . e($judulGrup) . '</td>';

        foreach ($rows as $b) {
            if (!empty($b['sub'])) {
                $n = count($b['sub']);
                foreach (array_values($b['sub']) as $i => $s) {
                    $h .= '<tr>' . $selGrup;
                    $selGrup = '';
                    if ($i === 0) {
                        $h .= '<td rowspan="' . $n . '" style="width:220px; vertical-align:middle;">' . e($b['label']) . '</td>';
                    }
                    $h .= '<td>' . lp_dt_input_html($s['field'], $nilaiDinamis, $nilaiPasangan, $s['hint'] ?? '') . '</td></tr>';
                }
            } else {
                $h .= '<tr>' . $selGrup;
                $selGrup = '';
                $h .= '<td style="width:220px;">' . e($b['label']) . '</td>'
                    . '<td>' . lp_dt_input_html($b['field'], $nilaiDinamis, $nilaiPasangan, $b['hint'] ?? '') . '</td></tr>';
            }
        }
    }

    return $h . '</tbody></table></div>'
        . '<small class="text-secondary text-xs">Kolom kosong otomatis tercetak "-" di Word. '
        . 'Satuan (Kg, mm, Ton, dll.) ditambahkan otomatis jika hanya diisi angka.</small>';
}

// ===== DATA TEKNIK (tabel, mengikuti tampilan Word) =====
// 'dari'  = baris hanya info, nilainya diambil dari field Data Umum (bukan input baru)
// 'sub'   = baris bertingkat (Container -> Diameter/Height), label induk di-rowspan
// 'grup'  = null -> baris tanpa kolom grup (Installed Power, dst.)
const LP_DTK_TABEL = [
    [
        'grup' => 'Spesifikasi Pesawat',
        'baris' => [
            ['label' => 'Jenis/ Tipe', 'dari' => 'merek_tipe'],
            ['label' => 'Kapasitas Kerja', 'dari' => 'kapasitas_keterangan'],
            ['field' => 'dtk_kapasitas_spec', 'label' => 'Kapasitas Sesuai Spec', 'hint' => 'Contoh: 180 (pcs/ min otomatis)'],
            ['field' => 'dtk_liquid_dosis', 'label' => 'Liquid Dosis Range (ml)', 'hint' => 'Boleh dikosongkan'],
        ],
    ],
    [
        'grup' => 'Dimensi',
        'baris' => [
            [
                'label' => 'Container',
                'sub' => [
                    ['field' => 'dtk_container_diameter', 'label' => 'Diameter', 'hint' => 'Contoh: 15 - 90 (mm otomatis)'],
                    ['field' => 'dtk_container_tinggi', 'label' => 'Height', 'hint' => 'Contoh: 30 - 250 (mm otomatis)'],
                ],
            ],
            [
                'label' => 'Label Size',
                'sub' => [
                    ['field' => 'dtk_label_tinggi', 'label' => 'Height', 'hint' => 'Contoh: 10 - 120 (mm otomatis)'],
                    ['field' => 'dtk_label_panjang', 'label' => 'Length', 'hint' => 'Contoh: 13 - 190 (mm otomatis)'],
                ],
            ],
        ],
    ],
    [
        'grup' => null,
        'baris' => [
            ['field' => 'dtk_daya_terpasang', 'label' => 'Installed Power', 'hint' => 'Contoh: 7 (kW otomatis)'],
            ['field' => 'dtk_konsumsi_udara', 'label' => 'Air Consumption With Two Vibrators', 'hint' => 'Contoh: 250 (Nl/min otomatis) atau ketik lengkap: 250 Nl/min (min 6 bar)'],
            ['field' => 'dtk_berat_netto', 'label' => 'Indicative Net Weight', 'hint' => 'Contoh: 1600 (kg otomatis)'],
            ['field' => 'dtk_output_mekanis', 'label' => 'Mechanical Output', 'hint' => 'Contoh: 300 (pz/ min otomatis)'],
            ['field' => 'dtk_tekanan', 'label' => 'Pressure', 'hint' => 'Contoh: 2.5 - 6.2 (bar otomatis)'],
        ],
    ],
];

// Satuan otomatis: berlaku untuk angka murni DAN rentang ("15 - 90" -> "15 - 90 mm")
const LP_DTK_SATUAN = [
    'dtk_kapasitas_spec' => 'pcs/ min',
    'dtk_container_diameter' => 'mm',
    'dtk_container_tinggi' => 'mm',
    'dtk_label_tinggi' => 'mm',
    'dtk_label_panjang' => 'mm',
    'dtk_daya_terpasang' => 'kW',
    'dtk_konsumsi_udara' => 'Nl/min',
    'dtk_berat_netto' => 'kg',
    'dtk_output_mekanis' => 'pz/ min',
    'dtk_tekanan' => 'bar',
];

// Kalau dikosongkan tetap kosong di Word (selain ini otomatis "-")
const LP_DTK_BIARKAN_KOSONG = ['dtk_liquid_dosis'];

/** Daftar datar semua field INPUT Data Teknik (baris 'dari' tidak ikut). */
function lp_dtk_semua_field(): array
{
    $semua = [];
    foreach (LP_DTK_TABEL as $g) {
        foreach ($g['baris'] as $b) {
            if (!empty($b['sub'])) {
                foreach ($b['sub'] as $s) {
                    $semua[] = $s['field'];
                }
            } elseif (!empty($b['field'])) {
                $semua[] = $b['field'];
            }
        }
    }
    return $semua;
}

/** Hanya baris yang placeholder-nya benar-benar ada di template. */
function lp_dtk_baris_aktif(array $grup, array $nilaiDinamis): array
{
    $hasil = [];
    foreach ($grup['baris'] as $b) {
        if (!empty($b['sub'])) {
            $sub = array_values(array_filter($b['sub'], fn($s) => array_key_exists($s['field'], $nilaiDinamis)));
            if ($sub) {
                $b['sub'] = $sub;
                $hasil[] = $b;
            }
        } elseif (!empty($b['dari'])) {
            if (array_key_exists($b['dari'], $nilaiDinamis)) {
                $hasil[] = $b;
            }
        } elseif (array_key_exists($b['field'], $nilaiDinamis)) {
            $hasil[] = $b;
        }
    }
    return $hasil;
}

/** Satuan otomatis + kosong -> "-" untuk field Data Teknik. */
function lp_terapkan_satuan_dtk(array $data): array
{
    foreach (LP_DTK_SATUAN as $field => $satuan) {
        if (!isset($data[$field])) {
            continue;
        }
        $v = trim((string) $data[$field]);
        // angka / rentang saja (harus ada minimal 1 digit, jadi "-" tidak jadi "- kW")
        if ($v !== '' && preg_match('/\d/', $v) && preg_match('/^[\d.,\s\-–\/]+$/u', $v)) {
            $data[$field] = $v . ' ' . $satuan;
        }
    }
    foreach (lp_dtk_semua_field() as $f) {
        if (in_array($f, LP_DTK_BIARKAN_KOSONG, true)) {
            continue;
        }
        if (array_key_exists($f, $data) && trim((string) $data[$f]) === '') {
            $data[$f] = '-';
        }
    }
    return $data;
}

/** HTML tabel input Data Teknik, bentuknya mengikuti tabel di Word. */
function lp_dtk_render_tabel(array $nilaiDinamis, array $nilaiPasangan): string
{
    $h = '<div class="table-responsive-custom"><table class="table-custom" style="margin:0;"><tbody>';

    foreach (LP_DTK_TABEL as $grup) {
        $rows = lp_dtk_baris_aktif($grup, $nilaiDinamis);
        if (!$rows) {
            continue;
        }
        $punyaGrup = !empty($grup['grup']);
        $total = 0;
        foreach ($rows as $b) {
            $total += !empty($b['sub']) ? count($b['sub']) : 1;
        }
        $selGrup = $punyaGrup
            ? '<td rowspan="' . $total . '" class="lp-dt-grup">' . e($grup['grup']) . '</td>'
            : '';
        $spanLabel = $punyaGrup ? 2 : 3;

        foreach ($rows as $b) {
            if (!empty($b['sub'])) {
                $n = count($b['sub']);
                foreach (array_values($b['sub']) as $i => $s) {
                    $h .= '<tr>' . $selGrup;
                    $selGrup = '';
                    if ($i === 0) {
                        $h .= '<td rowspan="' . $n . '" style="width:150px; vertical-align:middle;">' . e($b['label']) . '</td>';
                    }
                    $h .= '<td style="width:110px;">' . e($s['label']) . '</td>'
                        . '<td>' . lp_dt_input_html($s['field'], $nilaiDinamis, $nilaiPasangan, $s['hint'] ?? '') . '</td></tr>';
                }
            } elseif (!empty($b['dari'])) {
                $h .= '<tr>' . $selGrup;
                $selGrup = '';
                $h .= '<td colspan="' . $spanLabel . '" style="width:260px;">' . e($b['label']) . '</td>'
                    . '<td><span class="text-secondary text-xs fst-italic">Otomatis dari Data Umum ('
                    . e($b['dari']) . ')</span></td></tr>';
            } else {
                $h .= '<tr>' . $selGrup;
                $selGrup = '';
                $h .= '<td colspan="' . $spanLabel . '" style="width:260px;">' . e($b['label']) . '</td>'
                    . '<td>' . lp_dt_input_html($b['field'], $nilaiDinamis, $nilaiPasangan, $b['hint'] ?? '') . '</td></tr>';
            }
        }
    }

    return $h . '</tbody></table></div>'
        . '<small class="text-secondary text-xs">Kolom kosong otomatis tercetak "-" di Word (kecuali Liquid Dosis Range). '
        . 'Satuan ditambahkan otomatis jika hanya diisi angka atau rentang (mis. 15 - 90).</small>';
}

/**
 * Baca judul grup, lokasi, komponen, dan label pemeriksaan dari tabel Word (pola vfN_M_*).
 * Return: ['grup' => [1 => 'Pemeriksaan dengan Mesin Mati'],
 *          'item' => ['1_1' => ['lokasi'=>'Kerangka Utama / Chassis','komponen'=>'Rangka Penguat','label'=>'Korosi']]]
 */
function lp_scan_label_vf_docx(string $path): array
{
    $hasil = ['grup' => [], 'item' => []];
    $xml = lp_baca_xml_docx($path);
    if ($xml === '' || !preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return $hasil;
    }

    $polos = function (string $x): string {
        $x = preg_replace('/<\/w:p>|<w:br\b[^>]*\/>/', ' ', $x); // antar-paragraf jadi spasi
        $x = html_entity_decode(preg_replace('/<[^>]+>/', '', $x), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $x));
    };

    $lokasi = '';
    $komponen = '';
    $judulTerakhir = null;

    foreach ($barisList[0] as $xmlBaris) {
        if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
            continue;
        }

        // Susun sel + posisi kolom grid (memperhitungkan gridSpan & vMerge)
        $sel = [];
        $col = 0;
        foreach ($selList[0] as $xmlSel) {
            $span = preg_match('/<w:gridSpan\b[^>]*w:val="(\d+)"/', $xmlSel, $g) ? max(1, (int) $g[1]) : 1;
            $vm = null;
            if (preg_match('/<w:vMerge\b([^>]*)>/', $xmlSel, $v)) {
                $vm = strpos($v[1], 'restart') !== false ? 'restart' : 'lanjut';
            }
            $sel[] = ['col' => $col, 'span' => $span, 'vm' => $vm, 'teks' => $polos($xmlSel)];
            $col += $span;
        }
        $teksBaris = implode(' ', array_column($sel, 'teks'));

        // --- Baris item: mengandung ${vfN_M_ok} / _tdk ---
        if (preg_match('/vf(\d+)_(\d+)_(?:ok|tdk)/i', $teksBaris, $m)) {
            $no = (int) $m[1];
            $urut = (int) $m[2];

            if (!isset($hasil['grup'][$no]) && $judulTerakhir !== null) {
                $hasil['grup'][$no] = $judulTerakhir;
            }

            $label = '';
            foreach ($sel as $s) {
                if ($s['col'] > 2 || strpos($s['teks'], '{') !== false) {
                    continue;
                }
                $ada = $s['teks'] !== '' && $s['vm'] !== 'lanjut';
                if ($s['col'] === 0) {
                    if ($ada)
                        $lokasi = $s['teks'];
                } elseif ($s['col'] === 1 && $s['span'] >= 2) {
                    // Komponen & Pemeriksaan digabung (mis. "Pemberat (C/W)")
                    $komponen = '';
                    $label = $s['teks'];
                } elseif ($s['col'] === 1) {
                    if ($ada)
                        $komponen = $s['teks'];
                } elseif ($s['col'] === 2) {
                    $label = $s['teks'];
                }
            }

            $hasil['item'][$no . '_' . $urut] = [
                'lokasi' => $lokasi,
                'komponen' => $komponen,
                'label' => $label,
            ];
            continue;
        }

        // --- Baris judul grup: tanpa placeholder, teks awal "1. ...." ---
        if (
            strpos($teksBaris, '{') === false
            && preg_match('/^(\d+)\s*\.\s*(.+)$/su', $sel[0]['teks'] ?? '', $m)
        ) {
            $judulTerakhir = trim($m[2]);
            $lokasi = '';
            $komponen = '';
        }
    }
    return $hasil;
}

/** Kelompokkan field vfN_M_ok/tdk/ket jadi struktur grup -> item. */
function lp_kelompokkan_vf(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tmp = [];
    $sisa = [];
    foreach ($fields as $f) {
        if (preg_match(LP_POLA_VF, $f, $m)) {
            $tmp[(int) $m[1]][(int) $m[2]][strtolower($m[3])] = $f;
        } else {
            $sisa[] = $f;
        }
    }
    ksort($tmp);

    $hasil = [];
    foreach ($tmp as $no => $subs) {
        ksort($subs);
        $items = [];
        foreach ($subs as $urut => $g) {
            if (empty($g['ok']) || empty($g['tdk'])) { // pasangan tidak lengkap -> balik ke field biasa
                foreach ($g as $f) {
                    $sisa[] = $f;
                }
                continue;
            }
            $kunci = $no . '_' . $urut;
            $d = $labelDariDocx['item'][$kunci] ?? $labelLama['item'][$kunci] ?? [];
            $items[] = [
                'key' => 'vf' . $no . '_' . $urut,
                'urut' => $urut,
                'field_ok' => $g['ok'],
                'field_tdk' => $g['tdk'],
                'field_ket' => $g['ket'] ?? null,
                'lokasi' => $d['lokasi'] ?? '',
                'komponen' => $d['komponen'] ?? '',
                'label' => !empty($d['label']) ? $d['label'] : ('Item ' . $no . '.' . $urut),
            ];
        }
        if ($items) {
            $hasil[] = [
                'no' => $no,
                'judul' => $labelDariDocx['grup'][$no] ?? $labelLama['grup'][$no] ?? ('Kelompok ' . $no),
                'items' => $items,
            ];
        }
    }
    return ['vf' => $hasil, 'fields' => $sisa];
}

/**
 * Radio status -> nilai placeholder Word.
 *  ok     : Memenuhi "√"  | Tidak ""
 *  tdk    : Memenuhi ""   | Tidak "√"
 *  na     : "-" | "-"
 *  kosong : ""  | ""
 * Keterangan kosong diisi default (Baik / Tidak ditemukan / -), lihat konstanta di atas.
 */
function lp_expand_vf_ke_field(array $def, array $inputStatus, array $inputKet): array
{
    $ketDefault = ['ok' => LP_VF_KET_DEFAULT_OK, '' => LP_VF_KET_DEFAULT_KOSONG, 'tdk' => ''];
    $hasil = [];
    foreach ($def as $grup) {
        foreach ($grup['items'] as $it) {
            $st = $inputStatus[$it['key']] ?? '';
            if ($st === 'ok') {
                $hasil[$it['field_ok']] = '√';
                $hasil[$it['field_tdk']] = '';
            } elseif ($st === 'tdk') {
                $hasil[$it['field_ok']] = '';
                $hasil[$it['field_tdk']] = '√';
            } else {
                $hasil[$it['field_ok']] = '-';
                $hasil[$it['field_tdk']] = '-';
            }
            if (!empty($it['field_ket'])) {
                $ket = trim((string) ($inputKet[$it['key']] ?? ''));
                $hasil[$it['field_ket']] = $ket !== '' ? $ket : ($ketDefault[$st] ?? '');
            }
        }
    }
    return $hasil;
}

/** Field multi-baris: daftar statis + semua dcpN_ket. */
function lp_is_field_multiline(string $namaField): bool
{
    return in_array($namaField, LP_FIELD_MULTILINE, true)
        || (bool) preg_match(LP_POLA_FIELD_MULTILINE, $namaField);
}

/**
 * Baca label dari tabel Word (pola dcpN_* / dcpN_x_*).
 * Return: ['item' => ['1' => 'Pondasi Mesin', '9_a' => 'Tegangan'],
 *          'induk' => [19 => 'Sistem Elektrik', 24 => 'Alat Pengaman']]
 */
function lp_scan_label_dcp_docx(string $path): array
{
    $hasil = ['item' => [], 'induk' => []];
    $xml = lp_baca_xml_docx($path);
    if ($xml === '' || !preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return $hasil;
    }

    $polos = function (string $x): string {
        $x = preg_replace('/<\/w:p>|<w:br\b[^>]*\/>/', ' ', $x);
        $x = html_entity_decode(preg_replace('/<[^>]+>/', '', $x), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $x));
    };

    $noHeader = null;
    $labelHeader = null;

    foreach ($barisList[0] as $xmlBaris) {
        if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
            continue;
        }

        $sel = [];
        foreach ($selList[0] as $xmlSel) {
            $lanjut = false;
            if (preg_match('/<w:vMerge\b([^>]*)>/', $xmlSel, $v)) {
                $lanjut = strpos($v[1], 'restart') === false;
            }
            $sel[] = ['teks' => $polos($xmlSel), 'lanjut' => $lanjut];
        }

        // Posisi sel placeholder pertama (sel sebelumnya = No & label komponen)
        $idxPh = null;
        foreach ($sel as $i => $s) {
            if (preg_match('/dcp\d+(?:_[a-z]+)?_(?:baik|buruk|ket)/i', $s['teks'])) {
                $idxPh = $i;
                break;
            }
        }
        $batas = $idxPh ?? count($sel);

        $angka = null;
        $labels = [];
        foreach ($sel as $i => $s) {
            if ($i >= $batas || $s['lanjut'] || $s['teks'] === '') {
                continue;
            }
            if (preg_match('/^\d+$/', $s['teks'])) {
                if ($angka === null) {
                    $angka = (int) $s['teks'];
                }
                continue;
            }
            $labels[] = $s['teks'];
        }

        // Baris judul tanpa placeholder (mis. "24 | Alat Pengaman")
        if ($idxPh === null) {
            if ($angka !== null && $labels) {
                $noHeader = $angka;
                $labelHeader = $labels[0];
            }
            continue;
        }

        $teksBaris = implode(' ', array_column($sel, 'teks'));
        if (!preg_match('/dcp(\d+)(?:_([a-z]+))?_baik/i', $teksBaris, $m)) {
            continue;
        }
        $no = (int) $m[1];
        $sub = strtolower($m[2] ?? '');

        if ($sub === '') {
            if ($labels) {
                $hasil['item'][$no] = end($labels);
            }
        } else {
            if ($labels) {
                $hasil['item'][$no . '_' . $sub] = trim(preg_replace('/^[a-z]\s*\.\s*/u', '', end($labels)));
                // 2 label di baris yang sama = kolom induk (mis. "Sistem Elektrik") + label sub
                if (count($labels) > 1 && !isset($hasil['induk'][$no])) {
                    $hasil['induk'][$no] = $labels[0];
                }
            }
            if (!isset($hasil['induk'][$no]) && $noHeader === $no && $labelHeader !== null) {
                $hasil['induk'][$no] = $labelHeader;
            }
        }
        if ($noHeader !== $no) {
            $noHeader = null;
            $labelHeader = null;
        }
    }
    return $hasil;
}

/** Kelompokkan field dcpN[_x]_baik/buruk/ket jadi: grup(no) -> main + subs. */
function lp_kelompokkan_dcp(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tmp = [];
    $sisa = [];
    foreach ($fields as $f) {
        if (preg_match(LP_POLA_DCP, $f, $m)) {
            $tmp[(int) $m[1]][strtolower($m[2] ?? '')][strtolower($m[3])] = $f;
        } else {
            $sisa[] = $f;
        }
    }
    ksort($tmp);

    $buatItem = function (string $key, array $g) use (&$sisa): ?array {
        if (empty($g['baik']) || empty($g['buruk'])) { // pasangan tidak lengkap -> balik ke field biasa
            foreach ($g as $f) {
                $sisa[] = $f;
            }
            return null;
        }
        return [
            'key' => $key,
            'field_baik' => $g['baik'],
            'field_buruk' => $g['buruk'],
            'field_ket' => $g['ket'] ?? null,
        ];
    };

    $hasil = [];
    foreach ($tmp as $no => $bagian) {
        $main = isset($bagian['']) ? $buatItem('dcp' . $no, $bagian['']) : null;

        $kunciSub = array_values(array_filter(array_keys($bagian), fn($k) => $k !== ''));
        usort($kunciSub, fn($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        $subs = [];
        foreach ($kunciSub as $sub) {
            $it = $buatItem('dcp' . $no . '_' . $sub, $bagian[$sub]);
            if (!$it) {
                continue;
            }
            $kunci = $no . '_' . $sub;
            $it['sub'] = $sub;
            $it['label'] = $labelDariDocx['item'][$kunci] ?? $labelLama['item'][$kunci] ?? ('Item ' . $no . '.' . $sub);
            $subs[] = $it;
        }

        if ($main || $subs) {
            $hasil[] = [
                'no' => $no,
                'label' => $labelDariDocx['item'][$no] ?? $labelDariDocx['induk'][$no]
                    ?? $labelLama['grup'][$no] ?? ('Item ' . $no),
                'main' => $main,   // null = baris judul saja (tanpa √)
                'subs' => $subs,
            ];
        }
    }
    return ['dcp' => $hasil, 'fields' => $sisa];
}

/**
 * Radio -> nilai placeholder. baik: √|""  buruk: ""|√  na: -|-  kosong: ""|""
 */
function lp_expand_dcp_ke_field(array $def, array $inputStatus, array $inputKet): array
{
    $hasil = [];
    $isi = function (array $it) use (&$hasil, $inputStatus, $inputKet) {
        $st = $inputStatus[$it['key']] ?? '';
        $hasil[$it['field_baik']] = $st === 'baik' ? '√' : ($st === 'buruk' ? '' : '-');
        $hasil[$it['field_buruk']] = $st === 'buruk' ? '√' : ($st === 'baik' ? '' : '-');
        if (!empty($it['field_ket'])) {
            $hasil[$it['field_ket']] = trim((string) ($inputKet[$it['key']] ?? ''));
        }
    };
    foreach ($def as $g) {
        if (!empty($g['main'])) {
            $isi($g['main']);
        }
        foreach (($g['subs'] ?? []) as $s) {
            $isi($s);
        }
    }
    return $hasil;
}

/** HTML sel radio (Baik/Buruk/Tidak Ada/Kosong) + sel keterangan untuk satu baris. */
function lp_dcp_sel_kondisi(array $it, array $nilaiStatus, array $nilaiKet): string
{
    $st = $nilaiStatus[$it['key']] ?? '';
    $h = '';
    foreach (['baik', 'buruk'] as $opsi) {
        $h .= '<td style="text-align:center;"><input type="checkbox" class="lp-dcp-chk" name="dcp_status[' . e($it['key']) . ']"'
            . ' value="' . $opsi . '"' . ($st === $opsi ? ' checked' : '') . '></td>';
    }
    if (!empty($it['field_ket'])) {
        $ket = array_key_exists($it['key'], $nilaiKet) ? (string) $nilaiKet[$it['key']] : '';
        $h .= '<td><textarea class="form-control-custom lp-textarea-ket" name="dcp_ket[' . e($it['key']) . ']" rows="1"'
            . ' oninput="this.style.height=\'auto\';this.style.height=this.scrollHeight+\'px\'">'
            . e($ket) . '</textarea></td>';
    } else {
        $h .= '<td></td>';
    }
    return $h;
}

/** Daftar datar semua nama field tabel Data Teknis (Wheel Loader). */
function lp_dt_tabel_semua_field(): array
{
    $semua = [];
    foreach (LP_DATA_TEKNIS_TABEL as $baris) {
        foreach ($baris as $b) {
            if (!empty($b['sub'])) {
                foreach ($b['sub'] as $s) {
                    $semua[] = $s['field'];
                }
            } else {
                $semua[] = $b['field'];
            }
        }
    }
    return $semua;
}

/** Ambil hanya baris yang placeholder-nya benar-benar ada di template. */
function lp_dt_baris_aktif(array $barisGrup, array $nilaiDinamis): array
{
    $hasil = [];
    foreach ($barisGrup as $b) {
        if (!empty($b['sub'])) {
            $sub = array_values(array_filter($b['sub'], fn($s) => array_key_exists($s['field'], $nilaiDinamis)));
            if ($sub) {
                $b['sub'] = $sub;
                $hasil[] = $b;
            }
        } elseif (array_key_exists($b['field'], $nilaiDinamis)) {
            $hasil[] = $b;
        }
    }
    return $hasil;
}

/** HTML input untuk satu sel nilai (otomatis 2 kolom kalau field berpasangan). */
function lp_dt_input_html(string $field, array $nilaiDinamis, array $nilaiPasangan, string $hint = ''): string
{
    if (isset(LP_FIELD_PASANGAN[$field])) {
        $def = LP_FIELD_PASANGAN[$field];
        $html = '<div class="d-flex gap-2">';
        foreach (['kiri', 'kanan'] as $sisi) {
            $html .= '<input type="text" name="pasangan[' . e($field) . '][' . $sisi . ']"'
                . ' class="form-control-custom text-xs" style="flex:1 1 0; min-width:0;"'
                . ' placeholder="' . e($def[$sisi]) . '"'
                . ' value="' . e($nilaiPasangan[$field][$sisi] ?? '') . '">';
        }
        return $html . '</div>';
    }
    return '<input type="text" name="dinamis[' . e($field) . ']" class="form-control-custom text-xs"'
        . ' placeholder="' . e($hint) . '" value="' . e($nilaiDinamis[$field] ?? '') . '">';
}

/**
 * Pecah pasangan "lokasi_tahun_pembuatan" (kiri = Lokasi, kanan = Tahun)
 * untuk baris 2 & 3 tabel Motor Diesel. Kosong -> "-".
 */
function lp_hitung_turunan_motor_diesel(array $inputPasangan): array
{
    $kiri = trim((string) ($inputPasangan['lokasi_tahun_pembuatan']['kiri'] ?? ''));
    $kanan = trim((string) ($inputPasangan['lokasi_tahun_pembuatan']['kanan'] ?? ''));

    return [
        'md_negara' => $kiri !== '' ? $kiri : '-',
        'md_tahun_pembuatan' => $kanan !== '' ? $kanan : '-',
    ];
}

/**
 * Perhitungan Thickness Test (ASME VIII Div.1, UG-27 / UG-32).
 * Satuan harus konsisten: P & S sama-sama kg/cm², D & CA dalam mm -> t dalam mm.
 *  Shell        : t = P.R / (S.E - 0.6.P) + CA
 *  Head 2:1     : t = P.D / (2.S.E - 0.2.P) + CA
 *  Torispherical: t = 0.885.P.L / (S.E - 0.1.P) + CA   (L = D)
 *  Hemispherical: t = P.R / (2.S.E - 0.2.P) + CA
 * Kalau kolom manual (thk_tebal_*_manual) diisi, nilai manual yang dipakai.
 */
/** Format angka tanpa nol desimal berlebih, mis. 900.00 -> "900", 0.85 -> "0,85" */
function lp_format_angka_pas(float $angka, int $maxDesimal = 2): string
{
    $teks = number_format($angka, $maxDesimal, ',', '');
    if (strpos($teks, ',') !== false) {
        $teks = rtrim(rtrim($teks, '0'), ',');
    }
    return $teks;
}

/**
 * Perhitungan Thickness Test (ASME VIII Div.1, UG-27 / UG-32).
 *  Shell        : t = P.R / (S.E - 0.6.P) + CA
 *  Head 2:1     : t = P.D / (2.S.E - 0.2.P) + CA
 *  Torispherical: t = 0.885.P.L / (S.E - 0.1.P) + CA   (L = D)
 *  Hemispherical: t = P.R / (2.S.E - 0.2.P) + CA
 *
 * P diambil dari ${thk_tekanan_desain} (input manual khusus Thickness Test,
 * TIDAK memakai ${tekanan_kerja} dari Data Umum).
 *
 * ${thk_head_t_hitung} / ${thk_shell_t_hitung} = hasil rumus murni (t + CA),
 * TIDAK PERNAH ditimpa nilai manual -- supaya bisa dibandingkan dengan
 * ${thk_tebal_head} / ${thk_tebal_shell} (nilai aktual di lapangan).
 */
function lp_hitung_thickness(array $d): array
{
    $ca = lp_ke_angka($d['thk_ca'] ?? '') ?? 0.0;
    $e = lp_ke_angka($d['thk_efisiensi'] ?? '');
    $s = lp_ke_angka($d['thk_stress'] ?? '');
    $p = lp_ke_angka($d['thk_tekanan_desain'] ?? '');
    $r = lp_ke_angka($d['thk_jari_jari'] ?? '');
    $dia = lp_ke_angka($d['thk_diameter'] ?? '');

    // R input manual; kalau kosong, turunkan dari diameter. D = 2R.
    if ($r === null && $dia !== null)
        $r = $dia / 2;
    if ($r !== null)
        $dia = $r * 2;

    $kunci = [
        'pembilang',
        'penyebut',
        't_hitung',
        'simbol',
        'status',
        'kesimpulan',
    ];
    $hasil = [
        'thk_tebal_shell' => '',
        'thk_tebal_head' => '',
        'thk_head_jenis_teks' => '',
        'thk_head_rumus_pembilang' => '',
        'thk_head_rumus_penyebut' => ''
    ];
    foreach (['shell', 'head'] as $b) {
        foreach ($kunci as $k)
            $hasil["thk_{$b}_{$k}"] = '';
    }

    $bisaHitung = $e !== null && $e > 0 && $e <= 1
        && $r !== null && $r > 0
        && $s !== null && $s > 0
        && $p !== null && $p > 0;

    $minShell = $minHead = null;

    if ($bisaHitung) {
        $se = $s * $e;
        $pT = lp_format_angka_pas($p);
        $sT = lp_format_angka_pas($s);
        $eT = lp_format_angka_pas($e);
        $rT = lp_format_angka_pas($r);
        $dT = lp_format_angka_pas($dia);

        // ===== SHELL (UG-27): t = P.R / (S.E - 0.6.P) + CA =====
        $pen = $se - 0.6 * $p;
        if ($pen > 0) {
            $minShell = ($p * $r) / $pen + $ca;
            $hasil['thk_shell_pembilang'] = "{$pT} x {$rT}";
            $hasil['thk_shell_penyebut'] = "{$sT} x {$eT} - ( 0,6 x {$pT} )";
            $hasil['thk_shell_t_hitung'] = lp_format_angka_koma($minShell, 2);
        }

        // ===== HEAD / BOTTOM (UG-32) =====
        $t = null;
        switch (LP_THK_JENIS_HEAD) {
            case 'torispherical': // t = 0.885.P.L / (S.E - 0.1.P), L = D
                $pen = $se - 0.1 * $p;
                if ($pen > 0)
                    $t = (0.885 * $p * $dia) / $pen;
                $hasil['thk_head_jenis_teks'] = 'Torispherical';
                $hasil['thk_head_rumus_pembilang'] = '0,885 x P x L';
                $hasil['thk_head_rumus_penyebut'] = 'S x E - ( 0,1 x P )';
                $hasil['thk_head_pembilang'] = "0,885 x {$pT} x {$dT}";
                $hasil['thk_head_penyebut'] = "{$sT} x {$eT} - ( 0,1 x {$pT} )";
                break;
            case 'hemispherical': // t = P.R / (2.S.E - 0.2.P)
                $pen = 2 * $se - 0.2 * $p;
                if ($pen > 0)
                    $t = ($p * $r) / $pen;
                $hasil['thk_head_jenis_teks'] = 'Hemispherical';
                $hasil['thk_head_rumus_pembilang'] = 'P x R';
                $hasil['thk_head_rumus_penyebut'] = '2 x S x E - ( 0,2 x P )';
                $hasil['thk_head_pembilang'] = "{$pT} x {$rT}";
                $hasil['thk_head_penyebut'] = "2 x {$sT} x {$eT} - ( 0,2 x {$pT} )";
                break;
            default: // ellipsoidal 2:1, t = P.D / (2.S.E - 0.2.P)
                $pen = 2 * $se - 0.2 * $p;
                if ($pen > 0)
                    $t = ($p * $dia) / $pen;
                $hasil['thk_head_jenis_teks'] = 'Ellipsoidal 2:1';
                $hasil['thk_head_rumus_pembilang'] = 'P x D';
                $hasil['thk_head_rumus_penyebut'] = '2 x S x E - ( 0,2 x P )';
                $hasil['thk_head_pembilang'] = "{$pT} x {$dT}";
                $hasil['thk_head_penyebut'] = "2 x {$sT} x {$eT} - ( 0,2 x {$pT} )";
        }
        if ($t !== null) {
            $minHead = $t + $ca;
            $hasil['thk_head_t_hitung'] = lp_format_angka_koma($minHead, 2);
        }
    }

    // Nilai aktual (hasil ukur lapangan) HANYA dari input manual.
    $isi = function (string $b, string $manualRaw, ?float $min) use (&$hasil): void {
        $manualRaw = trim($manualRaw);
        if ($manualRaw === '')
            return;
        $aktual = lp_ke_angka($manualRaw);
        $hasil["thk_tebal_{$b}"] = $aktual !== null ? lp_format_angka_koma($aktual, 2) : $manualRaw;
        if ($aktual === null || $min === null)
            return;

        $aman = $aktual > $min;
        $hasil["thk_{$b}_simbol"] = $aman ? '>' : '<';
        $hasil["thk_{$b}_status"] = $aman ? 'Aman/ACC' : 'Tidak Aman/Belum ACC';
        $hasil["thk_{$b}_kesimpulan"] =
            'Dari hasil perhitungan, maka untuk hasil Thickness Test yang didapatkan '
            . lp_format_angka_koma($aktual, 2);
    };
    $isi('shell', $d['thk_tebal_shell_manual'] ?? '', $minShell);
    $isi('head', $d['thk_tebal_head_manual'] ?? '', $minHead);

    return $hasil;
}

/** Angka desimal biasa: "4.611" / "4,611" -> 4.611 (titik BUKAN ribuan). */
function lp_pnd_angka_desimal(string $nilai): ?float
{
    $nilai = str_replace(',', '.', preg_replace('/[^\d.,]/', '', trim($nilai)));
    return is_numeric($nilai) ? (float) $nilai : null;
}

/** Angka bulat/besar: "2.400" -> 2400 (titik = ribuan), "1500 rpm" -> 1500. */
function lp_pnd_angka_bulat(string $nilai): ?float
{
    return lp_ke_angka(preg_replace('/[^\d.,]/', '', trim($nilai)));
}

function lp_pnd_potong(float $angka, int $desimal = 2): float
{
    $f = 10 ** $desimal;
    if (!LP_PND_POTONG_DESIMAL) {
        return round($angka, $desimal);
    }
    return floor($angka * $f + 1e-9) / $f;
}

/** Format titik-desimal (sesuai contoh Word: 9.80, 19614.33). */
function lp_pnd_fmt(float $angka, int $desimal = 2, bool $tetap = true): string
{
    $teks = number_format($angka, $desimal, '.', '');
    if (!$tetap && strpos($teks, '.') !== false) {
        $teks = rtrim(rtrim($teks, '0'), '.');
    }
    return $teks;
}

/**
 * Perhitungan Pondasi (Analisis).
 *  W izin  = C x W mesin x sqrt(n)      [lbs]  -> / 2000 = Ton
 *  W aktual = P x L x T x (rho/1000)    [Ton]
 * n diambil dari gen_putaran (Data Teknis); kalau kosong pakai default 1500.
 */
function lp_hitung_pondasi(array $d): array
{
    $hasil = array_fill_keys(LP_PND_FIELD_OTOMATIS, '');

    $putaranMentah = trim((string) ($d['gen_putaran'] ?? ''));
    if ($putaranMentah === '') {
        $putaranMentah = (string) LP_DEFAULT_FIELD['gen_putaran'];
    }
    $n = lp_pnd_angka_bulat($putaranMentah);
    $wMesin = lp_pnd_angka_bulat((string) ($d['pnd_berat_mesin'] ?? ''));
    $rho = lp_pnd_angka_bulat((string) ($d['pnd_massa_jenis'] ?? ''));
    $c = lp_pnd_angka_desimal((string) ($d['pnd_koefisien'] ?? ''));
    $p = lp_pnd_angka_desimal((string) ($d['pnd_panjang'] ?? ''));
    $l = lp_pnd_angka_desimal((string) ($d['pnd_lebar'] ?? ''));
    $t = lp_pnd_angka_desimal((string) ($d['pnd_tinggi'] ?? ''));

    $wIzin = null;
    $wAktual = null;

    // --- Berat pondasi yang diijinkan ---
    if ($c > 0 && $wMesin > 0 && $n > 0) {
        $lbs = $c * $wMesin * sqrt($n);
        $wIzin = lp_pnd_potong($lbs / LP_PND_KONVERSI_LBS_KE_TON);

        $hasil['pnd_putaran'] = lp_pnd_fmt($n, 2, false);
        $hasil['pnd_w_lbs'] = lp_pnd_fmt(lp_pnd_potong($lbs));
        $hasil['pnd_w_ton'] = lp_pnd_fmt($wIzin);
    }

    // --- Berat pondasi riil ---
    if ($p > 0 && $l > 0 && $t > 0 && $rho > 0) {
        $vol = $p * $l * $t;
        $wAktual = lp_pnd_potong($vol * ($rho / 1000));

        $hasil['pnd_volume'] = lp_pnd_fmt(lp_pnd_potong($vol, 3), 3, false);
        $hasil['pnd_massa_jenis_ton'] = lp_pnd_fmt($rho / 1000, 3, false);
        $hasil['pnd_w_aktual'] = lp_pnd_fmt($wAktual);
    }

    // --- Kesimpulan: dibandingkan dari HASIL yang tampil di Word ---
    if ($hasil['pnd_w_ton'] !== '' && $hasil['pnd_w_aktual'] !== '') {
        // Ubah ke satuan 0,01 Ton (integer) supaya bebas selisih pecahan float
        $izinTampil = (int) round(((float) $hasil['pnd_w_ton']) * 100);
        $aktualTampil = (int) round(((float) $hasil['pnd_w_aktual']) * 100);

        if ($aktualTampil === $izinTampil) {
            $hasil['pnd_simbol'] = '~';   // sama
        } elseif ($aktualTampil > $izinTampil) {
            $hasil['pnd_simbol'] = '>';
        } else {
            $hasil['pnd_simbol'] = '<';
        }

        $ok = $aktualTampil >= $izinTampil;   // ~ dan > = terpenuhi
        $hasil['pnd_kesimpulan'] = $ok ? 'sudah terpenuhi' : 'belum terpenuhi';
        $hasil['pnd_status'] = $ok ? 'ACC' : 'Belum ACC';
    }

    return $hasil;
}

/**
 * Baca judul grup ("Shell/ Badan") dan label item ("Ketebalan") dari tabel Word.
 * Return: ['grup' => [1 => 'Shell/ Badan'], 'item' => ['1_a' => 'Ketebalan']]
 */
function lp_scan_label_dimensi_docx(string $path): array
{
    $hasil = ['grup' => [], 'item' => []];
    $xml = lp_baca_xml_docx($path);
    if ($xml === '' || !preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return $hasil;
    }

    $polos = fn(string $x) => trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $x), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    $judulTerakhir = null;

    foreach ($barisList[0] as $xmlBaris) {
        $teksBaris = $polos($xmlBaris);

        // --- Baris item: mengandung ${dimN_x} ---
        if (preg_match('/dim(\d+)_((?!ket\b)[a-z]+)/', $teksBaris, $m)) {
            $no = (int) $m[1];
            $sub = $m[2];

            if (!isset($hasil['grup'][$no]) && $judulTerakhir !== null) {
                $hasil['grup'][$no] = $judulTerakhir;
            }

            if (preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
                foreach ($selList[0] as $xmlSel) {
                    $teks = $polos($xmlSel);
                    if ($teks === '' || strpos($teks, '{') !== false) {
                        continue;
                    }
                    $teks = trim(preg_replace('/^[a-z]\s*\.\s*/u', '', $teks)); // buang awalan "a."
                    if ($teks === '' || ctype_digit(str_replace('.', '', $teks))) {
                        continue;
                    }
                    $hasil['item'][$no . '_' . $sub] = $teks;
                    break;
                }
            }
            continue;
        }

        // --- Baris judul grup: tanpa placeholder, sel pertama yang bukan angka ---
        if (strpos($teksBaris, '{') === false && preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
            foreach ($selList[0] as $xmlSel) {
                $teks = $polos($xmlSel);
                if ($teks === '' || ctype_digit(str_replace('.', '', $teks))) {
                    continue;
                }
                $judulTerakhir = $teks;
                break;
            }
        }
    }
    return $hasil;
}

/** Kelompokkan field dimN_x / dimN_x_ket jadi struktur grup -> item. */
function lp_kelompokkan_dimensi(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tmp = [];
    $sisa = [];
    foreach ($fields as $f) {
        if (preg_match(LP_POLA_DIMENSI, $f, $m)) {
            $tmp[(int) $m[1]][$m[2]][empty($m[3]) ? 'nilai' : 'ket'] = $f;
        } else {
            $sisa[] = $f;
        }
    }
    ksort($tmp);

    $dimensi = [];
    foreach ($tmp as $no => $subs) {
        uksort($subs, fn($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        $items = [];
        foreach ($subs as $sub => $g) {
            if (empty($g['nilai'])) { // hanya ada _ket tanpa kolom ukuran -> kembalikan ke field biasa
                foreach ($g as $f) {
                    $sisa[] = $f;
                }
                continue;
            }
            $kunci = $no . '_' . $sub;
            $items[] = [
                'key' => 'dim' . $no . '_' . $sub,
                'sub' => $sub,
                'field_nilai' => $g['nilai'],
                'field_ket' => $g['ket'] ?? null,
                'label' => $labelDariDocx['item'][$kunci] ?? $labelLama['item'][$kunci] ?? ('Item ' . $no . '.' . $sub),
            ];
        }
        if ($items) {
            $dimensi[] = [
                'no' => $no,
                'judul' => $labelDariDocx['grup'][$no] ?? $labelLama['grup'][$no] ?? ('Kelompok ' . $no),
                'items' => $items,
            ];
        }
    }
    return ['dimensi' => $dimensi, 'fields' => $sisa];
}

function lp_gabung_pasangan(string $kiri, string $kanan, string $pemisah = LP_PASANGAN_PEMISAH): string
{
    $kiri = trim($kiri);
    $kanan = trim($kanan);

    if ($kiri === '' && $kanan === '') {
        return '-';
    }
    return ($kiri !== '' ? $kiri : '-') . $pemisah . ($kanan !== '' ? $kanan : '-');
}

/** Angka murni + satuan -> "752 kW". Isi non-angka dibiarkan apa adanya. */
function lp_tambah_satuan_jika_angka(string $nilai, string $satuan): string
{
    $nilai = trim($nilai);
    if ($satuan !== '' && $nilai !== '' && preg_match('/^[\d.,]+$/', $nilai)) {
        return $nilai . ' ' . $satuan;
    }
    return $nilai;
}

function lp_expand_pasangan_ke_field(array $fieldsTemplate, array $inputPasangan): array
{
    $hasil = [];
    foreach ($fieldsTemplate as $f) {
        $nama = $f['field'] ?? '';
        if (!isset(LP_FIELD_PASANGAN[$nama])) {
            continue;
        }
        $def = LP_FIELD_PASANGAN[$nama];
        $hasil[$nama] = lp_gabung_pasangan(
            lp_tambah_satuan_jika_angka((string) ($inputPasangan[$nama]['kiri'] ?? ''), $def['satuan_kiri'] ?? ''),
            lp_tambah_satuan_jika_angka((string) ($inputPasangan[$nama]['kanan'] ?? ''), $def['satuan_kanan'] ?? ''),
            $def['pemisah'] ?? LP_PASANGAN_PEMISAH
        );
    }
    return $hasil;
}

/** Angka murni pada field tertentu -> ditambah satuan (mis. 705 -> "705 kW"). */
function lp_terapkan_satuan_otomatis(array $data): array
{
    foreach (LP_FIELD_SATUAN_OTOMATIS as $field => $satuan) {
        if (!isset($data[$field])) {
            continue;
        }
        $v = trim((string) $data[$field]);
        if ($v !== '' && preg_match('/^[\d.,]+$/', $v)) {
            $data[$field] = $v . ' ' . $satuan;
        }
    }
    return $data;
}

/**
 * Input form -> nilai untuk placeholder Word.
 * $inputNilai: ['dim1_a' => '5,98', ...]   $inputKet: ['dim1_a' => 'teks', ...]
 */
function lp_expand_dimensi_ke_field(array $dimensiDef, array $inputNilai, array $inputKet): array
{
    $hasil = [];
    foreach ($dimensiDef as $grup) {
        foreach ($grup['items'] as $it) {
            $nilai = trim((string) ($inputNilai[$it['key']] ?? ''));
            if ($nilai === '') {
                $nilai = '-';
            } elseif (LP_DIMENSI_UNIT_OTOMATIS !== '' && preg_match('/^[\d.,]+$/', $nilai)) {
                $nilai .= ' ' . LP_DIMENSI_UNIT_OTOMATIS;
            }
            $hasil[$it['field_nilai']] = $nilai;

            if (!empty($it['field_ket'])) {
                $hasil[$it['field_ket']] = trim((string) ($inputKet[$it['key']] ?? ''));
            }
        }
    }
    return $hasil;
}
/**
 * Baca judul kelompok ("A. KOMPONEN BEJANA") dan label item ("a. Shell / Badan")
 * dari tabel Word. Return: ['grup' => ['A' => 'KOMPONEN BEJANA'], 'item' => ['A_a' => 'Shell / Badan']]
 */
function lp_scan_label_visual_docx(string $path): array
{
    $hasil = ['grup' => [], 'item' => []];
    $xml = lp_baca_xml_docx($path);
    if ($xml === '' || !preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return $hasil;
    }

    $polos = fn(string $x) => trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $x), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    $judulTerakhir = []; // huruf => judul heading terdekat di ATAS baris item

    foreach ($barisList[0] as $xmlBaris) {
        $teksBaris = $polos($xmlBaris); // flatten dulu -> placeholder terpecah antar-run tetap terbaca

        // --- Baris item: mengandung ${visX_y_ok} / _tdk ---
        if (preg_match('/vis([A-Z])_([a-z]+)_(?:ok|tdk)/', $teksBaris, $m)) {
            $huruf = $m[1];
            $sub = $m[2];

            if (!isset($hasil['grup'][$huruf]) && isset($judulTerakhir[$huruf])) {
                $hasil['grup'][$huruf] = $judulTerakhir[$huruf];
            }

            if (preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
                foreach ($selList[0] as $xmlSel) {
                    $teks = $polos($xmlSel);
                    if ($teks === '' || strpos($teks, '{') !== false) {
                        continue;
                    }
                    $teks = trim(preg_replace('/^[a-z]\s*\.\s*/u', '', $teks)); // buang awalan "a."
                    if ($teks === '' || ctype_digit(str_replace('.', '', $teks))) {
                        continue;
                    }
                    $hasil['item'][$huruf . '_' . $sub] = $teks;
                    break;
                }
            }
            continue;
        }

        // --- Baris judul kelompok: tanpa placeholder, diawali "A." / "B." dst ---
        if (strpos($teksBaris, '{') === false && preg_match('/^([A-Z])\s*\.\s*(.+)$/su', $teksBaris, $m)) {
            $judulTerakhir[$m[1]] = trim($m[2]);
        }
    }
    return $hasil;
}

/**
 * Kelompokkan field visX_y_ok/tdk/ket jadi struktur grup -> item.
 * Label dari DOCX diprioritaskan (Word = sumber kebenaran), cache lama jadi cadangan.
 */
function lp_kelompokkan_visual(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tmp = [];
    $sisa = [];
    foreach ($fields as $f) {
        if (preg_match(LP_POLA_VISUAL, $f, $m)) {
            $tmp[$m[1]][$m[2]][$m[3]] = $f;
        } else {
            $sisa[] = $f;
        }
    }
    ksort($tmp);

    $visual = [];
    foreach ($tmp as $huruf => $subs) {
        uksort($subs, fn($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        $items = [];
        foreach ($subs as $sub => $g) {
            if (empty($g['ok']) || empty($g['tdk'])) { // pasangan tidak lengkap -> kembalikan ke field biasa
                foreach ($g as $f) {
                    $sisa[] = $f;
                }
                continue;
            }
            $kunci = $huruf . '_' . $sub;
            $items[] = [
                'key' => 'vis' . $huruf . '_' . $sub,
                'sub' => $sub,
                'field_ok' => $g['ok'],
                'field_tdk' => $g['tdk'],
                'field_ket' => $g['ket'] ?? null,
                'label' => $labelDariDocx['item'][$kunci] ?? $labelLama['item'][$kunci] ?? ('Item ' . $huruf . '.' . $sub),
            ];
        }
        if ($items) {
            $visual[] = [
                'kode' => $huruf,
                'judul' => $labelDariDocx['grup'][$huruf] ?? $labelLama['grup'][$huruf] ?? ('Kelompok ' . $huruf),
                'items' => $items,
            ];
        }
    }
    return ['visual' => $visual, 'fields' => $sisa];
}

/**
 * Status checkbox (2 opsi, bukan radio 3 opsi lagi) -> nilai untuk 2 placeholder.
 * $inputStatus: ['visA_a' => 'ok'|'tdk'|(tidak ada/kosong), ...]   $inputKet: ['visA_a' => 'teks', ...]
 *
 * - 'ok'   : Memenuhi Syarat dicentang       -> field_ok = '√', field_tdk = ''
 * - 'tdk'  : Tidak Memenuhi Syarat dicentang -> field_ok = '',  field_tdk = '√'
 * - kosong : belum/batal dipilih keduanya    -> field_ok = '-', field_tdk = '-'
 */
function lp_expand_visual_ke_field(array $visualDef, array $inputStatus, array $inputKet): array
{
    $hasil = [];
    foreach ($visualDef as $grup) {
        foreach ($grup['items'] as $it) {
            $status = $inputStatus[$it['key']] ?? '';
            if ($status === 'ok') {
                $hasil[$it['field_ok']] = '√';
                $hasil[$it['field_tdk']] = '';
            } elseif ($status === 'tdk') {
                $hasil[$it['field_ok']] = '';
                $hasil[$it['field_tdk']] = '√';
            } else {
                $hasil[$it['field_ok']] = '-';
                $hasil[$it['field_tdk']] = '-';
            }
            if (!empty($it['field_ket'])) {
                $hasil[$it['field_ket']] = trim((string) ($inputKet[$it['key']] ?? ''));
            }
        }
    }
    return $hasil;
}

/**
 * Baca judul grup ("KONSTRUKSI DASAR") & label item ("Pondasi Dasar") dari tabel Word (pola ckX_N_*).
 * Return: ['grup' => ['A' => 'KONSTRUKSI DASAR'], 'item' => ['A_1' => 'Pondasi Dasar']]
 */
function lp_scan_label_ck_docx(string $path): array
{
    $hasil = ['grup' => [], 'item' => []];
    $xml = lp_baca_xml_docx($path);
    if ($xml === '' || !preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return $hasil;
    }

    $polos = fn(string $x) => trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $x), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    $headingHuruf = null;
    $headingJudul = null;

    foreach ($barisList[0] as $xmlBaris) {
        $teksBaris = $polos($xmlBaris);

        // --- Baris item: mengandung ${ckA_1_baik} / _buruk ---
        if (preg_match('/ck([A-Z])_(\d+)_(?:baik|buruk)/', $teksBaris, $m)) {
            $huruf = $m[1];
            $no = (int) $m[2];

            // pakai heading terdekat di atas, HANYA jika hurufnya sama
            if (!isset($hasil['grup'][$huruf]) && $headingHuruf === $huruf) {
                $hasil['grup'][$huruf] = $headingJudul;
            }

            if (preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
                foreach ($selList[0] as $xmlSel) {
                    $teks = $polos($xmlSel);
                    if ($teks === '' || strpos($teks, '{') !== false) {
                        continue;
                    }
                    if (ctype_digit(str_replace('.', '', $teks))) { // sel "NO"
                        continue;
                    }
                    if (in_array($teks, ['-', '√'], true)) {
                        continue;
                    }
                    $hasil['item'][$huruf . '_' . $no] = $teks;
                    break;
                }
            }
            continue;
        }

        // --- Baris judul grup: tanpa placeholder, diawali "A." / "B." dst ---
        if (strpos($teksBaris, '{') === false && preg_match('/^([A-Z])\s*\.\s*(.+)$/su', $teksBaris, $m)) {
            $headingHuruf = $m[1];
            $headingJudul = trim($m[2]);
        }
    }
    return $hasil;
}

/** Kelompokkan field ckX_N_baik/buruk/ket jadi struktur grup -> item. */
function lp_kelompokkan_ck(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tmp = [];
    $sisa = [];
    foreach ($fields as $f) {
        if (preg_match(LP_POLA_CK, $f, $m)) {
            $tmp[$m[1]][(int) $m[2]][$m[3]] = $f;
        } else {
            $sisa[] = $f;
        }
    }
    ksort($tmp);

    $hasil = [];
    foreach ($tmp as $huruf => $subs) {
        ksort($subs);
        $items = [];
        foreach ($subs as $no => $g) {
            if (empty($g['baik']) || empty($g['buruk'])) { // pasangan tidak lengkap -> balik ke field biasa
                foreach ($g as $f) {
                    $sisa[] = $f;
                }
                continue;
            }
            $kunci = $huruf . '_' . $no;
            $items[] = [
                'key' => 'ck' . $huruf . '_' . $no,
                'no' => $no,
                'field_baik' => $g['baik'],
                'field_buruk' => $g['buruk'],
                'field_ket' => $g['ket'] ?? null,
                'label' => $labelDariDocx['item'][$kunci] ?? $labelLama['item'][$kunci] ?? ('Item ' . $huruf . '.' . $no),
            ];
        }
        if ($items) {
            $hasil[] = [
                'kode' => $huruf,
                'judul' => $labelDariDocx['grup'][$huruf] ?? $labelLama['grup'][$huruf] ?? ('Kelompok ' . $huruf),
                'items' => $items,
            ];
        }
    }
    return ['ck' => $hasil, 'fields' => $sisa];
}


/**
 * Checkbox (2 opsi) -> nilai placeholder Word.
 *  baik   : Baik "√" | Buruk ""
 *  buruk  : Baik ""  | Buruk "√"
 *  kosong : Baik "-" | Buruk "-"   (tidak ada yang dipilih)
 */
function lp_expand_ck_ke_field(array $def, array $inputStatus, array $inputKet): array
{
    $hasil = [];
    foreach ($def as $grup) {
        foreach ($grup['items'] as $it) {
            $st = $inputStatus[$it['key']] ?? '';
            if ($st === 'baik') {
                $hasil[$it['field_baik']] = '√';
                $hasil[$it['field_buruk']] = '';
            } elseif ($st === 'buruk') {
                $hasil[$it['field_baik']] = '';
                $hasil[$it['field_buruk']] = '√';
            } else {
                $hasil[$it['field_baik']] = '-';
                $hasil[$it['field_buruk']] = '-';
            }
            if (!empty($it['field_ket'])) {
                $hasil[$it['field_ket']] = trim((string) ($inputKet[$it['key']] ?? ''));
            }
        }
    }
    return $hasil;
}

/**
 * Baca judul kelompok, sub-judul, dan label item dari tabel Word (pola kvN_M_*).
 * Return: [
 *   'grup' => [1 => 'KOMPONEN PESAWAT'],
 *   'sub'  => ['1_1' => 'Header Atas', '1_6' => 'Header Bawah'],
 *   'item' => ['1_1' => 'Kondisi permukaan plate cover', ...]
 * ]
 */
function lp_scan_label_ketel_docx(string $path): array
{
    $hasil = ['grup' => [], 'sub' => [], 'item' => []];
    $xml = lp_baca_xml_docx($path);
    if ($xml === '' || !preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return $hasil;
    }

    $polos = fn(string $x) => trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $x), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    $judulGrup = null;
    $judulSub = null;

    foreach ($barisList[0] as $xmlBaris) {
        $teksBaris = $polos($xmlBaris);

        // --- Baris item: mengandung ${kvN_M_baik} / _buruk ---
        if (preg_match('/kv(\d+)_(\d+)_(?:baik|buruk)/i', $teksBaris, $m)) {
            $no = (int) $m[1];
            $urut = (int) $m[2];
            $kunci = $no . '_' . $urut;

            if (!isset($hasil['grup'][$no]) && $judulGrup !== null) {
                $hasil['grup'][$no] = $judulGrup;
            }
            if ($judulSub !== null) {
                $hasil['sub'][$kunci] = $judulSub;
            }

            if (preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
                foreach ($selList[0] as $xmlSel) {
                    $teks = $polos($xmlSel);
                    if ($teks === '' || strpos($teks, '{') !== false) {
                        continue;
                    }
                    $teks = trim(preg_replace('/^[a-z]\s*\.\s*/u', '', $teks));
                    if ($teks === '' || ctype_digit(str_replace('.', '', $teks))) {
                        continue;
                    }
                    $hasil['item'][$kunci] = $teks;
                    break;
                }
            }
            continue;
        }

        // --- Baris tanpa placeholder: judul kelompok ATAU sub-judul ---
        if (strpos($teksBaris, '{') !== false) {
            continue;
        }
        if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
            continue;
        }
        $teksSel = array_map($polos, $selList[0]);
        if (!array_filter($teksSel, fn($t) => $t !== '')) {
            continue;
        }

        $pertama = $teksSel[0] ?? '';
        if ($pertama !== '' && !ctype_digit(str_replace('.', '', $pertama))) {
            // teks ada di sel PERTAMA -> judul kelompok
            $judulGrup = $pertama;
            $judulSub = null;
        } else {
            // sel pertama kosong, teks di sel berikutnya -> sub-judul
            foreach (array_slice($teksSel, 1) as $t) {
                if ($t !== '') {
                    $judulSub = $t;
                    break;
                }
            }
        }
    }
    return $hasil;
}

/** Kelompokkan field kvN_M_baik/buruk/ket jadi struktur grup -> item. */
function lp_kelompokkan_ketel_visual(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tmp = [];
    $sisa = [];
    foreach ($fields as $f) {
        if (preg_match(LP_POLA_KETEL_VISUAL, $f, $m)) {
            $tmp[(int) $m[1]][(int) $m[2]][strtolower($m[3])] = $f;
        } else {
            $sisa[] = $f;
        }
    }
    ksort($tmp);

    $hasil = [];
    foreach ($tmp as $no => $subs) {
        ksort($subs);
        $items = [];
        foreach ($subs as $urut => $g) {
            if (empty($g['baik']) || empty($g['buruk'])) { // pasangan tidak lengkap -> balik ke field biasa
                foreach ($g as $f) {
                    $sisa[] = $f;
                }
                continue;
            }
            $kunci = $no . '_' . $urut;
            $items[] = [
                'key' => 'kv' . $no . '_' . $urut,
                'urut' => $urut,
                'field_baik' => $g['baik'],
                'field_buruk' => $g['buruk'],
                'field_ket' => $g['ket'] ?? null,
                'sub_judul' => $labelDariDocx['sub'][$kunci] ?? null,
                'label' => $labelDariDocx['item'][$kunci] ?? $labelLama['item'][$kunci] ?? ('Item ' . $no . '.' . $urut),
            ];
        }
        if ($items) {
            $hasil[] = [
                'no' => $no,
                'judul' => $labelDariDocx['grup'][$no] ?? $labelLama['grup'][$no] ?? ('Kelompok ' . $no),
                'items' => $items,
            ];
        }
    }
    return ['ketel_visual' => $hasil, 'fields' => $sisa];
}

/**
 * Radio status -> nilai placeholder Word.
 *  baik   : Baik "√"  | Buruk ""
 *  buruk  : Baik ""   | Buruk "√"
 *  na     : Baik "-"  | Buruk "-"   (tidak ada)
 *  kosong : Baik ""   | Buruk ""
 */
function lp_expand_ketel_visual_ke_field(array $def, array $inputStatus, array $inputKet): array
{
    $hasil = [];
    foreach ($def as $grup) {
        foreach ($grup['items'] as $it) {
            $st = $inputStatus[$it['key']] ?? '';
            if ($st === 'baik') {
                $hasil[$it['field_baik']] = '√';
                $hasil[$it['field_buruk']] = '';
            } elseif ($st === 'buruk') {
                $hasil[$it['field_baik']] = '';
                $hasil[$it['field_buruk']] = '√';
            } else {
                $hasil[$it['field_baik']] = '-';
                $hasil[$it['field_buruk']] = '-';
            }
            if (!empty($it['field_ket'])) {
                $hasil[$it['field_ket']] = trim((string) ($inputKet[$it['key']] ?? ''));
            }
        }
    }
    return $hasil;
}

/** Daftar datar semua nama field checklist bejana. */
function lp_chk_bejana_semua_field(): array
{
    $semua = [];
    foreach (LP_CHK_BEJANA_GRUP as $grup) {
        $semua = array_merge($semua, array_keys($grup));
    }
    return $semua;
}

// Field yang kalau dikosongkan tercetak "n/a" (bukan "-") di Word
const LP_CHK_BEJANA_FIELD_NA = [
    'head_depan_jenis',
    'head_depan_lengkungan',
    'head_depan_kemiringan',
    'head_depan_diameter',
    'head_depan_ketebalan',
    'head_depan_material',
    'head_belakang_jenis',
    'head_belakang_lengkungan',
    'head_belakang_kemiringan',
    'head_belakang_diameter',
    'head_belakang_ketebalan',
    'head_belakang_material',
    'pipa_keluaran_diameter',
    'pipa_masukan_diameter',
];

function lp_format_chk_bejana(string $field, string $nilai): string
{
    $nilai = trim($nilai);
    if ($nilai === '') {
        return in_array($field, LP_CHK_BEJANA_FIELD_NA, true) ? 'n/a' : '-';
    }
    if (in_array($field, LP_CHK_BEJANA_SATUAN_MM, true) && preg_match('/^[\d.,]+$/', $nilai)) {
        return $nilai . ' mm';
    }
    return $nilai;
}

/**
 * Hitung / isi field otomatis "Pengujian yang digunakan".
 * $inputCentang: ['isolasi' => '1', 'pembumian' => '1', ...] — hanya berisi
 * key yang DICENTANG (checkbox yang tidak dicentang memang tidak terkirim
 * lewat POST, jadi cukup dicek keberadaannya).
 */
function lp_hitung_pengujian(array $inputCentang): array
{
    $hasil = [];
    foreach (LP_PENGUJIAN_TEKS as $key => $teks) {
        $dicentang = !empty($inputCentang[$key]);
        $hasil['pengujian_' . $key . '_catatan'] = $dicentang ? $teks : '';
    }
    return $hasil;
}

/**
 * Perhitungan Jenis dan Ukuran Kabel yang digunakan.
 * Jumlah inti diambil dari ANGKA AWAL field "kabel_ukuran"
 * (mis. "8 x 185 mm2" -> 8).
 * KHA total = jumlah_inti x kabel_kha_satuan (yang diinput manual dari tabel PUIL).
 * Dibandingkan dengan KHA Pengantar Utama (1.25 x In) yang sudah dihitung
 * di blok Perhitungan Arus Nominal.
 */
/**
 * Perhitungan Jenis dan Ukuran Kabel yang digunakan.
 *
 * kabel_ukuran diinput TANPA "mm2" (mis. "8 x 185"), karena "mm2" sudah
 * jadi teks tetap di template Word. Jumlah inti diambil dari ANGKA AWAL
 * kabel_ukuran (mis. "8 x 185" -> 8).
 *
 * KHA total = jumlah_inti x kabel_kha_satuan (diinput manual dari tabel PUIL).
 * kabel_kha_total dikembalikan TANPA suffix " A" karena satuan "A" sudah
 * ditulis tetap di template Word setelah placeholder ini.
 */
function lp_hitung_jenis_kabel(array $dataFormMentah): array
{
    $ukuran = trim($dataFormMentah['kabel_ukuran'] ?? '');
    $khaSatuan = lp_ke_angka($dataFormMentah['kabel_kha_satuan'] ?? '');

    $jumlahInti = null;
    if (preg_match('/^\s*(\d+)/', $ukuran, $m)) {
        $jumlahInti = (int) $m[1];
    }

    if ($jumlahInti === null || $jumlahInti <= 0 || $khaSatuan === null) {
        return [
            'kabel_jumlah_inti' => '',
            'kabel_kha_rumus' => '',
            'kabel_kha_total' => '',
            'kabel_simbol' => '',
            'kabel_status' => '',
        ];
    }

    $khaTotal = $jumlahInti * $khaSatuan;

    // Format angka satuan KHA (buang nol desimal yang tidak perlu, mis. 637.00 -> 637)
    $khaSatuanText = rtrim(rtrim(number_format($khaSatuan, 2, ',', '.'), '0'), ',');
    $khaTotalText = rtrim(rtrim(number_format($khaTotal, 2, ',', '.'), '0'), ',');

    // Bandingkan dengan KHA Pengantar Utama (1.25 x In) dari blok Arus Nominal
    $inMentah = lp_hitung_arus_in_mentah($dataFormMentah);
    $khaHitung = $inMentah !== null ? (1.25 * $inMentah) : null;

    $simbol = '';
    $status = '';
    if ($khaHitung !== null) {
        $memenuhi = $khaTotal > $khaHitung; // KHA kabel harus LEBIH BESAR dari KHA hitung
        $simbol = $memenuhi ? '>' : '<';
        $status = $memenuhi ? 'ACC' : 'Belum ACC';
    }

    return [
        'kabel_jumlah_inti' => (string) $jumlahInti,
        'kabel_kha_rumus' => $jumlahInti . ' x ' . $khaSatuanText,
        'kabel_kha_total' => $khaTotalText,   // <<< tanpa " A", karena "A" sudah teks tetap di Word
        'kabel_simbol' => $simbol,
        'kabel_status' => $status,
    ];
}

function lp_ke_angka(string $nilai): ?float
{
    $nilai = trim($nilai);
    if ($nilai === '')
        return null;

    if (strpos($nilai, ',') !== false) {
        // format Indonesia: titik = ribuan, koma = desimal
        $nilai = str_replace('.', '', $nilai);
        $nilai = str_replace(',', '.', $nilai);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $nilai)) {
        // "1.030" -> ribuan
        $nilai = str_replace('.', '', $nilai);
    }
    // selain itu ("5.98", "1.5") titik dianggap desimal
    return is_numeric($nilai) ? (float) $nilai : null;
}

function lp_format_ribuan(float $angka): string
{
    return number_format($angka, 0, ',', ' ');
}

function lp_format_angka_koma(float $angka, int $desimal = 2): string
{
    return number_format($angka, $desimal, ',', '.');
}

/** Hitung nilai In (Ampere) mentah, dipakai internal (bukan placeholder langsung). */
function lp_hitung_arus_in_mentah(array $dataFormMentah): ?float
{
    $daya = lp_ke_angka($dataFormMentah['arus_daya_terpasang'] ?? '');
    $tegangan = lp_ke_angka($dataFormMentah['arus_tegangan'] ?? '');
    $cosphi = lp_ke_angka($dataFormMentah['arus_cosphi'] ?? '');

    if ($daya === null || $tegangan === null || $cosphi === null || $tegangan <= 0 || $cosphi <= 0) {
        return null;
    }

    $dayaKw = $daya * $cosphi;
    $dayaWatt = $dayaKw * 1000;

    return $dayaWatt / ($tegangan * $cosphi * sqrt(3));
}

function lp_hitung_arus_nominal(array $dataFormMentah): array
{
    $in = lp_hitung_arus_in_mentah($dataFormMentah);

    if ($in === null) {
        return [
            'arus_daya_watt' => '',
            'arus_in' => '',
            'arus_kha' => '',
            'kha_pengantar_utama' => '',
        ];
    }

    $daya = lp_ke_angka($dataFormMentah['arus_daya_terpasang']);
    $cosphi = lp_ke_angka($dataFormMentah['arus_cosphi']);
    $dayaKw = $daya * $cosphi;
    $dayaWatt = $dayaKw * 1000;
    $kha = 1.25 * $in;

    $khaFormatted = lp_format_angka_koma($kha, 2) . ' A';

    return [
        'arus_daya_watt' => lp_format_angka_koma($dayaKw, 1) . ' kW = ' . lp_format_ribuan($dayaWatt) . ' Watt',
        'arus_in' => lp_format_angka_koma($in, 2),
        'arus_kha' => $khaFormatted,
        'kha_pengantar_utama' => $khaFormatted,
    ];
}

/**
 * Perhitungan Pembatas Arus / Rating Proteksi Utama.
 * CB (rating utama) = 115% x In (arus nominal mentah, BUKAN nilai KHA).
 * Kesimpulan dibandingkan dengan Kapasitas MCCB/pembatas yang diinput manual.
 */
/**
 * Perhitungan Pembatas Arus / Rating Proteksi Utama.
 * CB (rating utama) = 115% x In (arus nominal mentah, BUKAN nilai KHA).
 * Kesimpulan dibandingkan dengan Kapasitas MCCB/pembatas yang diinput manual.
 */
function lp_hitung_proteksi_utama(array $dataFormMentah): array
{
    $inAngka = lp_hitung_arus_in_mentah($dataFormMentah);
    $kapasitas = lp_ke_angka($dataFormMentah['proteksi_kapasitas'] ?? '');
    $jenis = trim($dataFormMentah['proteksi_jenis'] ?? '') ?: 'Pembatas Arus';

    if ($inAngka === null) {
        return [
            'proteksi_rating_cb' => '',
            'proteksi_kesimpulan' => '',
            'proteksi_status' => '',   // <<< BARU
        ];
    }

    $ratingCb = 1.15 * $inAngka;
    $ratingCbFormatted = lp_format_angka_koma($ratingCb, 2) . ' A';

    $kesimpulan = '';
    $status = '';
    if ($kapasitas !== null) {
        $kapasitasText = rtrim(rtrim(number_format($kapasitas, 2, ',', '.'), '0'), ',');
        $memenuhi = $kapasitas < $ratingCb; // < = Belum ACC, > (atau =) = ACC
        $simbol = $memenuhi ? '<' : '>';
        $status = $memenuhi ? 'Belum ACC' : 'ACC';   // <<< "Sudah ACC" diganti jadi "ACC"

        // Kalimat kesimpulan TANPA status di akhir -- status ditaruh di
        // placeholder terpisah (${proteksi_status}) supaya bisa diformat
        // BOLD langsung di template Word, tanpa perlu utak-atik XML manual.
        $kesimpulan = "Kapasitas {$jenis} yang terpasang {$kapasitasText} A {$simbol} "
            . lp_format_angka_koma($ratingCb, 2)
            . " A Perhitungan Rating Utama CB, Maka";
    }

    return [
        'proteksi_rating_cb' => $ratingCbFormatted,
        'proteksi_kesimpulan' => $kesimpulan,
        'proteksi_status' => $status,   // <<< BARU -- diisi ke placeholder terpisah yang di-bold di Word
    ];
}

/**
 * Perhitungan Keseimbangan Beban RST.
 * I unbalance (%) = { |IR/Irata-1| + |IS/Irata-1| + |IT/Irata-1| } / 3 x 100%
 * ACC jika unbalance <= 20%, Belum ACC jika > 20%.
 */
function lp_hitung_keseimbangan_rst(array $dataFormMentah): array
{
    $kosong = [
        'rst_jumlah_arus' => '',
        'rst_arus_rata_rata' => '',
        'rst_a' => '',
        'rst_b' => '',
        'rst_c' => '',
        'rst_selisih_a' => '',
        'rst_selisih_b' => '',
        'rst_selisih_c' => '',
        'rst_jumlah_selisih' => '',
        'rst_unbalance' => '',
        'rst_simbol' => '',
        'rst_status' => '',
    ];

    $r = lp_ke_angka($dataFormMentah['rst_arus_r'] ?? '');
    $s = lp_ke_angka($dataFormMentah['rst_arus_s'] ?? '');
    $t = lp_ke_angka($dataFormMentah['rst_arus_t'] ?? '');

    if ($r === null || $s === null || $t === null) {
        return $kosong;
    }

    $jumlah = $r + $s + $t;
    $rata = $jumlah / 3;

    if ($rata <= 0) {
        return $kosong;
    }

    $a = $r / $rata;
    $b = $s / $rata;
    $c = $t / $rata;

    $selisihA = abs($a - 1);
    $selisihB = abs($b - 1);
    $selisihC = abs($c - 1);

    $jumlahSelisih = $selisihA + $selisihB + $selisihC;
    $unbalance = ($jumlahSelisih / 3) * 100;

    $belumAcc = $unbalance > LP_RST_BATAS_UNBALANCE;
    $simbol = $belumAcc ? '>' : '<';
    $status = $belumAcc ? 'Belum ACC' : 'ACC';

    return [
        'rst_jumlah_arus' => lp_format_angka_koma($jumlah, 0),
        'rst_arus_rata_rata' => lp_format_angka_koma($rata, 1),
        'rst_a' => lp_format_angka_koma($a, 3),
        'rst_b' => lp_format_angka_koma($b, 3),
        'rst_c' => lp_format_angka_koma($c, 3),
        'rst_selisih_a' => lp_format_angka_koma($selisihA, 3),
        'rst_selisih_b' => lp_format_angka_koma($selisihB, 3),
        'rst_selisih_c' => lp_format_angka_koma($selisihC, 3),
        'rst_jumlah_selisih' => lp_format_angka_koma($jumlahSelisih, 3),
        'rst_unbalance' => lp_format_angka_koma($unbalance, 2),
        'rst_simbol' => $simbol,
        'rst_status' => $status,
    ];
}

// ===== Urutan kolom tetap untuk tabel item, dipakai di tab Buat Laporan =====
/**
 * Urutkan array table_fields sesuai prioritas nama kolom yang dikenal
 * (titik, alat, tahanan, keterangan). Kolom dengan nama lain (dari template
 * lain yang tidak memakai pola ini) tetap ditampilkan, ditaruh setelah
 * kolom yang dikenal, sesuai urutan aslinya dari hasil scan.
 */
function lp_urutkan_kolom_tabel(array $tableFields): array
{
    $prioritas = ['titik' => 0, 'alat' => 1, 'tahanan' => 2, 'keterangan' => 3];
    usort($tableFields, function ($a, $b) use ($prioritas) {
        $pa = $prioritas[$a['field']] ?? 99;
        $pb = $prioritas[$b['field']] ?? 99;
        return $pa <=> $pb;
    });
    return $tableFields;
}

/* =========================================================
 * UTILITAS UMUM
 * ========================================================= */

function lp_slugify_nama_template(string $nama): string
{
    $nama = trim($nama);
    if (function_exists('iconv')) {
        $hasil = @iconv('UTF-8', 'ASCII//TRANSLIT', $nama);
        if ($hasil !== false) {
            $nama = $hasil;
        }
    }
    $nama = strtolower($nama);
    $nama = preg_replace('/[^a-z0-9]+/', '-', $nama);
    $nama = trim($nama, '-');
    return $nama !== '' ? $nama : 'template-laporan';
}

/** Deteksi kolom bertipe tanggal dari NAMA field-nya. */
function lp_is_kolom_tanggal(string $namaField): bool
{
    return (bool) preg_match('/(^|_)(tanggal|tgl|date)($|_)/i', $namaField);
}

function lp_format_tanggal_indonesia(string $tanggalYmd): string
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
    if (!$ts) {
        return $tanggalYmd;
    }
    return date('d', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Nama hari Indonesia dari tanggal Y-m-d. */
function lp_nama_hari_indonesia(string $tanggalYmd): string
{
    $namaHari = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu',
    ];
    $ts = strtotime($tanggalYmd);
    if (!$ts) {
        return '';
    }
    return $namaHari[date('l', $ts)] ?? '';
}

/** Gabungan nama hari + tanggal Indonesia, mis. "Senin, 17 September 2026". */
/** Gabungan nama hari + tanggal Indonesia, mis. "Senin 27 April 2026" (tanpa koma). */
function lp_hari_tanggal_indonesia(string $tanggalYmd): string
{
    $hari = lp_nama_hari_indonesia($tanggalYmd);
    $tanggal = lp_format_tanggal_indonesia($tanggalYmd);
    if ($hari === '') {
        return $tanggal; // fallback -- tanggal gagal diparse, tampilkan apa adanya
    }
    return $hari . ' ' . 'tanggal' . ' ' . $tanggal;
}

/** Deteksi apakah $namaField adalah field "_hari" pendamping sebuah field
 *  tanggal (mis. tanggal_pemeriksaan_hari -> induknya tanggal_pemeriksaan). */
function lp_field_tanggal_induk_dari_hari(string $namaField): ?string
{
    if (preg_match('/^(.+)_hari$/i', $namaField, $m) && lp_is_kolom_tanggal($m[1])) {
        return $m[1];
    }
    return null;
}

/**
 * Ambil struktur field hasil scan (['fields'=>[...], ...]) lalu:
 * - keluarkan field "*_hari" yang punya induk tanggal dari daftar yang
 *   ditampilkan di form (field_dinamis_lp)
 * - simpan pasangannya di key 'field_hari' => [field_hari => field_tanggal_induk]
 *   supaya bisa diisi otomatis saat generate.
 */
function lp_finalisasi_fields(array $hasil): array
{
    $tampil = [];
    $peta = [];
    $pengujianAktif = [];   // <<< BARU: key item pengujian yang placeholder-nya ADA di template

    foreach (($hasil['fields'] ?? []) as $f) {
        $induk = lp_field_tanggal_induk_dari_hari($f['field']);
        if ($induk !== null) {
            $peta[$f['field']] = $induk;
            continue;
        }
        if (in_array($f['field'], LP_ARUS_FIELD_OTOMATIS, true))
            continue;
        if (in_array($f['field'], LP_PROTEKSI_FIELD_OTOMATIS, true))
            continue;
        if (in_array($f['field'], LP_RST_FIELD_OTOMATIS, true))
            continue;
        if (in_array($f['field'], LP_KABEL_FIELD_OTOMATIS, true))
            continue;
        if (in_array($f['field'], LP_THK_FIELD_OTOMATIS, true))
            continue;
        if (in_array($f['field'], LP_DATA_TEKNIS_FIELD_OTOMATIS, true))
            continue;
        if (in_array($f['field'], LP_PND_FIELD_OTOMATIS, true))
            continue;
        if (in_array($f['field'], LP_KMP_FIELD_OTOMATIS, true))
            continue;
        if (in_array($f['field'], LP_AKM_FIELD_OTOMATIS, true))   // <<< BARU
            continue;

        // <<< DIUBAH: catat dulu item pengujian yang ada, baru disembunyikan dari input teks
        if (in_array($f['field'], LP_PENGUJIAN_FIELD_OTOMATIS, true)) {
            if (preg_match('/^pengujian_(.+)_catatan$/i', $f['field'], $m)) {
                $key = strtolower($m[1]);
                if (isset(LP_PENGUJIAN_TEKS[$key])) {
                    $pengujianAktif[$key] = $key;
                }
            }
            continue;
        }
        $tampil[] = $f;
    }
    $hasil['fields'] = $tampil;
    $hasil['field_hari'] = $peta;
    $hasil['pengujian_aktif'] = array_values($pengujianAktif);   // <<< BARU
    return $hasil;
}

function lp_label_dari_field(string $field): string
{
    $khusus = [
        'arus_daya_terpasang' => 'Daya Terpasang (kVA)',
        'arus_tegangan' => 'Tegangan (V)',
        'arus_cosphi' => 'Cosphi',
        'proteksi_jenis' => 'Jenis (Pembatas Arus/CB)',
        'proteksi_kapasitas' => 'Kapasitas Terpasang (A)',
        'rst_arus_r' => 'Arus Fasa R (A)',
        'rst_arus_s' => 'Arus Fasa S (A)',
        'rst_arus_t' => 'Arus Fasa T (A)',
        'kabel_jenis' => 'Jenis Kabel',
        'kabel_ukuran' => 'Ukuran (mm²) — cth: 8 x 185',   // <<< diubah, tanpa "mm2" di contoh
        'kabel_kha_satuan' => 'KHA per Kabel (A) — Tabel PUIL 2011',    // <<< TAMBAHAN
        'no_pengesahan' => 'No. Pengesahan',
        'lokasi_unit' => 'Lokasi Unit',
        'nama_operator' => 'Nama Operator',
        'jenis_bejana' => 'Jenis Bejana',
        'pabrik_pembuat' => 'Pabrik Pembuat',
        'model_type' => 'Model / Type',
        'no_seri' => 'No. Serie / No. Unit',
        'tempat_pembuatan' => 'Tempat Pembuatan',
        'tahun_pembuatan' => 'Tahun Pembuatan',
        'kapasitas_volume' => 'Kapasitas/Volume (Liter)',
        'tekanan_kerja' => 'Tekanan Kerja (kg/cm²)',
        'tekanan_desain' => 'Tekanan Desain (kg/cm²)',
        'isi_bejana' => 'Isi Bejana',
        'temperatur_kerja' => 'Temperatur Kerja (°C)',
        'standar_yang_dipakai' => 'Standar yang Dipakai',
        'digunakan_untuk' => 'Digunakan Untuk',
        'alamat_perusahaan' => 'Alamat Perusahaan',
        'thk_material' => 'Material',
        'thk_ca' => 'Corrosion Allowance (CA) (mm)',
        'thk_tekanan_desain' => 'Tekanan Desain (P) (kg/cm²)',
        'thk_efisiensi' => 'Joint Efficiency (E) — cth: 0,85',
        'thk_diameter' => 'Diameter (D) (mm)',
        'thk_kapasitas' => 'Kapasitas (Liter)',
        'thk_stress' => 'Stress Value (S) (kg/cm²)',
        'thk_jari_jari' => 'Jari-jari Dalam (R) (mm)',
        'jenis_ketel_uap' => 'Jenis Ketel Uap',
        'kapasitas_uap' => 'Kapasitas Uap (Kg/h)',
        'tekanan_desain_mpa' => 'Tekanan Desain (MPa)',
        'luas_pemanasan' => 'Luas Pemanasan (m²)',
        'media_bahan_bakar' => 'Media Bahan Bakar',
        'penanggung_jawab' => 'Pengurus / Penanggung Jawab',
        'jenis_pesawat' => 'Jenis Pesawat / Tipe',
        'no_seri_mesin' => 'No. Seri',
        'no_unit_mesin' => 'No. Unit',
        'pembuat_pemasang' => 'Perusahaan Pembuat / Pemasang',
        'kapasitas_daya' => 'Kapasitas (kW)',
        'bahan_bakar' => 'Bahan Bakar',
        'nama_juru_las' => 'Nama / No. Sertifikat Juru Las',
        'no_skp_pjk3' => 'No. SKP / Bidang PJK3',
        'no_skp_ak3' => 'No. SKP / Bidang AK3',
        'sertifikasi_standar' => 'Sertifikasi Standar',
        'klasifikasi' => 'Klasifikasi',
        'izin_pemakaian' => 'Nomor Izin Pemakaian / Penerbit',
        'riwayat_motor_diesel' => 'Data Riwayat Motor Diesel',
        'teg_jam' => 'Jam Pelaksanaan Test',
        'pnd_berat_mesin' => 'Berat Mesin (Kg)',
        'pnd_massa_jenis' => 'Massa Jenis Beton (ρ) (Kg/m³)',
        'pnd_koefisien' => 'Koefisien (C)',
        'pnd_panjang' => 'Panjang Pondasi (m)',
        'pnd_lebar' => 'Lebar Pondasi (m)',
        'pnd_tinggi' => 'Tinggi Pondasi (m)',
        'kapasitas_kerja' => 'Kapasitas Kerja (Kg)',
        'kapasitas_bucket' => 'Kapasitas Bucket (m³)',
        'tinggi_angkat' => 'Tinggi Angkat (mm)',
        'tenaga_penggerak' => 'Tenaga Penggerak',
        'izin_pakai' => 'Izin Pemakaian',
        'data_riwayat' => 'Data Riwayat',
        'no_lisensi_operator' => 'No. Lisensi K3 Operator',
        'kmp_diameter_torak' => 'Diameter Torak (D) (cm)',
        'no_seri_unit_mesin' => 'No Seri / No Unit',
        'kapasitas_keterangan' => 'Kapasitas',
        'no_seri_unit_kendaraan' => 'No. Serie / No. Unit',
        'no_unit_registrasi' => 'No Unit / No Registrasi Kendaraan',
        'kapasitas_spesifikasi' => 'Kapasitas Spesifikasi',
        'kapasitas_pengujian' => 'Kapasitas Pengujian',
        'tinggi_angkat_bak' => 'Tinggi Angkat',
        'akm_diameter_torak' => 'D Torak (cm)',
        'akm_tekanan' => 'Working Pressure (P) (kg/cm²)',
        'akm_jumlah_torak' => 'Jumlah Torak (n)',
        'akm_massa_jenis' => 'Massa Jenis Batu Split (ρ) (kg/m³)',
        'akm_kapasitas_spesifikasi' => 'Kapasitas Bak / Kapasitas Spesifikasi (m³)',
        'pelaksana' => 'Dilaksanakan Oleh',
        'perusahaan_pemakai' => 'Perusahaan Pemakai',
        'kmp_kapasitas_bucket' => 'Kapasitas Bucket / SWL (m³)',
        'kmp_tekanan_mpa' => 'Working Pressure (P) (MPa)',

    ];
    return $khusus[$field] ?? ucwords(str_replace('_', ' ', $field));
}
/** Nama file aman untuk laporan hasil upload manual. */
function lp_nama_file_manual(string $namaPerusahaan, string $ext): string
{
    $namaPerusahaan = trim($namaPerusahaan) !== '' ? trim($namaPerusahaan) : 'Tanpa Nama';
    $nama = date('Ymd') . '_' . $namaPerusahaan;
    $nama = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $nama);
    $nama = preg_replace('/\s+/', ' ', trim($nama));
    return $nama . '.' . strtolower($ext);
}

/**
 * Ambil salinan lokal sementara template dari Drive, jalankan callback,
 * lalu file sementara dihapus apa pun hasilnya.
 */
function lp_dengan_template_sementara(string $driveFileId, callable $callback)
{
    $unduhan = arp_unduh_dari_drive($driveFileId);
    if (!$unduhan) {
        throw new RuntimeException('Gagal mengambil template laporan dari Google Drive: ' . arp_drive_last_error());
    }
    try {
        return $callback($unduhan['path'], $unduhan['mime_type'] ?? '');
    } finally {
        if (is_file($unduhan['path'])) {
            @unlink($unduhan['path']);
        }
    }
}

/* =========================================================
 * FOLDER GOOGLE DRIVE
 * ========================================================= */

function lp_folder_template(string $namaBidang, string $namaUnit): string
{
    $bidang = str_replace('/', '-', trim($namaBidang)) ?: 'Lainnya';
    $unit = str_replace('/', '-', trim($namaUnit)) ?: 'Lainnya';
    return "Template_Laporan_Pemeriksaan/{$bidang}/{$unit}";
}

function lp_folder_hasil(string $namaBidang, string $namaUnit, ?DateTimeInterface $tanggal = null): string
{
    $tanggal = $tanggal ?? new DateTime();
    $bidang = str_replace('/', '-', trim($namaBidang)) ?: 'Lainnya';
    $unit = str_replace('/', '-', trim($namaUnit)) ?: 'Lainnya';
    $namaBulanId = [
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
    $bulan = $namaBulanId[(int) $tanggal->format('n')];
    return "Laporan_Pemeriksaan/{$bidang}/{$unit}/{$tanggal->format('Y')}/{$bulan}";
}

/* =========================================================
 * BACA ISI .DOCX
 * PENTING: TIDAK mengecek ekstensi file sama sekali. File hasil upload PHP
 * ($_FILES[...]['tmp_name']) maupun hasil unduhan Drive namanya acak tanpa
 * ".docx" -- inilah penyebab placeholder tidak pernah terbaca kalau dicek
 * pakai pathinfo(). Yang dicek di sini: file benar-benar bisa dibuka sebagai
 * ZIP dan punya entri word/document.xml.
 * ========================================================= */

function lp_baca_xml_docx(string $path): string
{
    if (!is_file($path) || filesize($path) < 4) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ''; // bukan docx (mis. .pdf / file rusak)
    }
    $utama = $zip->getFromName('word/document.xml');
    if ($utama === false) {
        $zip->close();
        return '';
    }
    $gabungan = $utama;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nama = (string) $zip->getNameIndex($i);
        if (preg_match('#^word/(header|footer)\d*\.xml$#', $nama)) {
            $isi = $zip->getFromName($nama);
            if ($isi !== false) {
                $gabungan .= $isi;
            }
        }
    }
    $zip->close();
    return $gabungan;
}

function lp_teks_polos_docx(string $path): string
{
    $xml = lp_baca_xml_docx($path);
    if ($xml === '') {
        return '';
    }
    $plain = preg_replace('/<[^>]+>/', '', $xml);
    return html_entity_decode($plain, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Scan semua placeholder ${nama} / {nama} dari sebuah file .docx.
 * Return: ['fields' => ['nama_pemilik', ...], 'table_fields' => ['uraian', ...]]
 * (table_fields = placeholder ${item_xxx} TANPA prefix "item_")
 */
function lp_scan_placeholder_docx(string $path): array
{
    $plain = lp_teks_polos_docx($path);
    if ($plain === '') {
        return ['fields' => [], 'table_fields' => []];
    }

    preg_match_all(
        '/\$\{\s*([a-zA-Z0-9_]+)\s*\}|(?<!\$)\{\s*([a-zA-Z0-9_]+)\s*\}/',
        $plain,
        $m
    );

    $semua = array_values(array_unique(array_filter(
        array_merge($m[1], $m[2]),
        fn($f) => $f !== ''
    )));

    $fields = [];
    $tableFields = [];
    foreach ($semua as $f) {
        if (in_array(strtolower($f), LP_FIELD_OTOMATIS, true)) {
            continue;
        }
        if (stripos($f, LP_PREFIX_KOLOM_TABEL) === 0) {
            $tableFields[] = substr($f, strlen(LP_PREFIX_KOLOM_TABEL));
        } else {
            $fields[] = $f;
        }
    }

    return [
        'fields' => array_values(array_unique($fields)),
        'table_fields' => array_values(array_unique($tableFields)),
    ];
}

/**
 * Baca label "Nama Barang" untuk tiap baris checklist, dengan membaca sel
 * lain di baris <w:tr> yang sama dengan placeholder cekN_baik.
 * Return: [1 => 'Jenis Air Terminal', 2 => 'Jarak/Radius Proteksi', ...]
 */
function lp_scan_label_checklist_docx(string $path): array
{
    $xml = lp_baca_xml_docx($path);
    if ($xml === '') {
        return [];
    }

    $label = [];
    if (!preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return [];
    }

    foreach ($barisList[0] as $xmlBaris) {
        // PENTING: flatten dulu teks baris ini (buang semua tag) SEBELUM
        // dicek pola cekN_baik -- supaya placeholder yang terpecah antar-run
        // Word (sangat umum terjadi) tetap terbaca utuh, sama seperti cara
        // lp_teks_polos_docx() bekerja untuk lp_scan_placeholder_docx().
        $teksBarisFlat = html_entity_decode(
            preg_replace('/<[^>]+>/', '', $xmlBaris),
            ENT_QUOTES | ENT_XML1,
            'UTF-8'
        );

        if (!preg_match('/cek(\d+)_baik/i', $teksBarisFlat, $mNo)) {
            continue; // baris ini benar-benar bukan baris checklist
        }
        $no = (int) $mNo[1];

        if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
            continue;
        }

        foreach ($selList[0] as $xmlSel) {
            $teks = trim(html_entity_decode(
                preg_replace('/<[^>]+>/', '', $xmlSel),
                ENT_QUOTES | ENT_XML1,
                'UTF-8'
            ));
            if ($teks === '')
                continue;
            if (strpos($teks, '{') !== false)
                continue;
            if (ctype_digit(str_replace('.', '', $teks)))
                continue;

            $label[$no] = $teks;
            break;
        }
    }

    return $label;
}

/**
 * Kelompokkan daftar 'fields' hasil scan jadi checklist (cekN_baik/tidak/ket)
 * dan sisanya (field biasa).
 *
 * $labelLama       : peta 'cekN' => label lama (dari fields_json lama, biar
 *                     label yang sudah pernah diedit admin tidak ketimpa).
 * $labelDariDocx   : peta no => label hasil lp_scan_label_checklist_docx().
 *
 * Return: [
 *   'checklist' => [ ['no'=>1,'field_baik'=>'cek1_baik','field_tidak'=>'cek1_tidak','field_ket'=>'cek1_ket','label'=>'Jenis Air Terminal'], ... ],
 *   'fields'    => [...field non-checklist yang tersisa...]
 * ]
 */
function lp_kelompokkan_checklist(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $grup = [];
    $sisa = [];

    // --- Pass 1: tangkap ${cekAWAL_AKHIR_ket} (keterangan gabungan hasil merge) ---
    $ketGabungan = []; // [no => ['field' => 'cek25_28_ket', 'awal' => 25, 'akhir' => 28]]
    $fieldsSisa = [];
    foreach ($fields as $f) {
        if (preg_match(LP_POLA_CEK_KET_GABUNGAN, $f, $m)) {
            $awal = (int) $m[1];
            $akhir = (int) $m[2];
            if ($akhir < $awal) {
                [$awal, $akhir] = [$akhir, $awal];
            }
            for ($no = $awal; $no <= $akhir; $no++) {
                $ketGabungan[$no] = ['field' => $f, 'awal' => $awal, 'akhir' => $akhir];
            }
        } else {
            $fieldsSisa[] = $f;
        }
    }

    // --- Pass 2: cekN_baik / cekN_tidak / cekN_ket (format lama, tanpa merge) ---
    foreach ($fieldsSisa as $f) {
        if (preg_match('/^' . LP_PREFIX_CEK . '(\d+)_(baik|tidak|ket)$/i', $f, $m)) {
            $no = (int) $m[1];
            $jenis = strtolower($m[2]);
            $grup[$no][$jenis] = $f;
        } else {
            $sisa[] = $f;
        }
    }

    ksort($grup);
    $checklist = [];
    foreach ($grup as $no => $g) {
        if (empty($g['baik']) || empty($g['tidak'])) {
            foreach ($g as $f)
                $sisa[] = $f;
            continue;
        }
        $key = 'cek' . $no;

        // Prioritaskan keterangan gabungan (merge) kalau ada
        $fieldKet = null;
        $ketSpan = null;
        $ketIsAwal = false;
        if (isset($ketGabungan[$no])) {
            $info = $ketGabungan[$no];
            $fieldKet = $info['field'];
            $ketIsAwal = ($no === $info['awal']);
            $ketSpan = $ketIsAwal ? ($info['akhir'] - $info['awal'] + 1) : null;
        } elseif (!empty($g['ket'])) {
            $fieldKet = $g['ket'];
            $ketIsAwal = true;
            $ketSpan = 1;
        }

        $checklist[] = [
            'no' => $no,
            'field_baik' => $g['baik'],
            'field_tidak' => $g['tidak'],
            'field_ket' => $fieldKet,
            'ket_span' => $ketSpan,          // jumlah baris yang di-rowspan (hanya terisi di baris "awal")
            'ket_tampilkan' => $ketIsAwal,   // false = baris ini "disembunyikan" krn tercakup rowspan baris di atasnya
            'label' => $labelLama[$key]
                ?? $labelDariDocx[$no]
                ?? ('Item Pemeriksaan ' . $no),
        ];
    }

    return ['checklist' => $checklist, 'fields' => $sisa];
}

/**
 * Ubah input radio (baik/tidak) jadi nilai "√"/"-" untuk kedua kolom,
 * siap digabung ke $dataForm sebelum lp_generate_docx().
 *
 * $inputStatus     : ['cek1' => 'baik', 'cek2' => 'tidak', ...]
 * $inputKeterangan : ['cek1' => 'Konvensional', ...]
 */
function lp_expand_checklist_ke_field(array $checklistDef, array $inputStatus, array $inputKeterangan): array
{
    $hasil = [];
    $ketSudahDiisi = []; // cegah field ket gabungan ketimpa '' oleh baris tersembunyi

    foreach ($checklistDef as $item) {
        $key = 'cek' . $item['no'];
        $status = $inputStatus[$key] ?? '';

        if ($status === 'baik') {
            $hasil[$item['field_baik']] = '√';
            $hasil[$item['field_tidak']] = '';
        } elseif ($status === 'tidak') {
            $hasil[$item['field_baik']] = '';
            $hasil[$item['field_tidak']] = '√';
        } else {
            $hasil[$item['field_baik']] = '-';
            $hasil[$item['field_tidak']] = '-';
        }

        if ($item['field_ket'] && $item['ket_tampilkan']) {
            $nilaiKet = trim((string) ($inputKeterangan[$key] ?? ''));
            $hasil[$item['field_ket']] = $nilaiKet;
            $ketSudahDiisi[$item['field_ket']] = true;
        } elseif ($item['field_ket'] && !isset($ketSudahDiisi[$item['field_ket']])) {
            $hasil[$item['field_ket']] = $hasil[$item['field_ket']] ?? '';
        }
    }
    return $hasil;
}

/**
 * Baca label "Obyek/Komponen" untuk tiap baris ${hasilN}, dengan membaca
 * sel lain di baris <w:tr> yang sama.
 * Return: [1 => 'Putaran Poros Diesel', 2 => 'Pembumian (Grounding)', ...]
 */
function lp_scan_label_hasil_docx(string $path): array
{
    $xml = lp_baca_xml_docx($path);
    if ($xml === '') {
        return ['tunggal' => [], 'sub' => []];
    }

    $labelTunggal = [];
    $labelSub = [];

    if (!preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return ['tunggal' => [], 'sub' => []];
    }

    foreach ($barisList[0] as $xmlBaris) {
        $teksFlat = html_entity_decode(preg_replace('/<[^>]+>/', '', $xmlBaris), ENT_QUOTES | ENT_XML1, 'UTF-8');

        // --- Baris grup: ada hasilN_M ---
        if (preg_match('/hasil(\d+)_(\d+)\b/i', $teksFlat, $mSub)) {
            $no = (int) $mSub[1];
            preg_match_all('/hasil' . $no . '_(\d+)\b/i', $teksFlat, $mAll);
            $urutanM = array_map('intval', $mAll[1]);
            if (!$urutanM)
                continue;

            if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList))
                continue;

            foreach ($selList[0] as $xmlSel) {
                $teksSel = html_entity_decode(preg_replace('/<[^>]+>/', '', $xmlSel), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (strpos($teksSel, '{') !== false || trim($teksSel) === '')
                    continue;
                if (ctype_digit(str_replace(['.', ' '], '', trim($teksSel))))
                    continue;

                preg_match_all('/<w:p\b.*?<\/w:p>/s', $xmlSel, $paragraf);
                $baris = [];
                foreach ($paragraf[0] as $p) {
                    $t = trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $p), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($t !== '')
                        $baris[] = $t;
                }
                if (count($baris) < count($urutanM))
                    continue;

                foreach ($urutanM as $idx => $m) {
                    $labelSub[$no][$m] = $baris[$idx] ?? "Sub Item {$no}.{$m}";
                }
                break;
            }
            continue;
        }

        // --- Baris tunggal: hasilN biasa (kode lama) ---
        if (preg_match('/\bhasil(\d+)\b(?!_)/i', $teksFlat, $mNo)) {
            $no = (int) $mNo[1];
            if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList))
                continue;
            foreach ($selList[0] as $xmlSel) {
                $teks = trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $xmlSel), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($teks === '' || strpos($teks, '{') !== false)
                    continue;
                if (ctype_digit(str_replace('.', '', $teks)))
                    continue;
                $labelTunggal[$no] = $teks;
                break;
            }
        }
    }

    return ['tunggal' => $labelTunggal, 'sub' => $labelSub];
}

/**
 * Baca label "Komponen yang Diuji" untuk tiap baris ${ukurN} / ${ukurN_M},
 * dengan membaca sel lain di baris <w:tr> yang sama.
 * Return: ['tunggal' => [1=>'Putaran Poros Diesel', ...], 'sub' => [4=>[1=>'Depan panel Motor Diesel', ...]]]
 */
function lp_scan_label_ukur_docx(string $path): array
{
    $xml = lp_baca_xml_docx($path);
    if ($xml === '') {
        return ['tunggal' => [], 'sub' => []];
    }

    $labelTunggal = [];
    $labelSub = [];

    if (!preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return ['tunggal' => [], 'sub' => []];
    }

    foreach ($barisList[0] as $xmlBaris) {
        $teksFlat = html_entity_decode(preg_replace('/<[^>]+>/', '', $xmlBaris), ENT_QUOTES | ENT_XML1, 'UTF-8');

        // --- Baris grup: ada ukurN_M ---
        if (preg_match('/ukur(\d+)_(\d+)\b/i', $teksFlat, $mSub)) {
            $no = (int) $mSub[1];
            preg_match_all('/ukur' . $no . '_(\d+)\b/i', $teksFlat, $mAll);
            $urutanM = array_map('intval', $mAll[1]);
            if (!$urutanM)
                continue;

            if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList))
                continue;

            foreach ($selList[0] as $xmlSel) {
                $teksSel = html_entity_decode(preg_replace('/<[^>]+>/', '', $xmlSel), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (strpos($teksSel, '{') !== false || trim($teksSel) === '')
                    continue;
                if (ctype_digit(str_replace(['.', ' '], '', trim($teksSel))))
                    continue;

                preg_match_all('/<w:p\b.*?<\/w:p>/s', $xmlSel, $paragraf);
                $baris = [];
                foreach ($paragraf[0] as $p) {
                    $t = trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $p), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($t !== '')
                        $baris[] = $t;
                }
                if (count($baris) < count($urutanM))
                    continue;

                foreach ($urutanM as $idx => $m) {
                    $labelSub[$no][$m] = $baris[$idx] ?? "Sub Item {$no}.{$m}";
                }
                break;
            }
            continue;
        }

        // --- Baris tunggal: ukurN biasa ---
        if (preg_match('/\bukur(\d+)\b(?!_)/i', $teksFlat, $mNo)) {
            $no = (int) $mNo[1];
            if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList))
                continue;
            foreach ($selList[0] as $xmlSel) {
                $teks = trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $xmlSel), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($teks === '' || strpos($teks, '{') !== false)
                    continue;
                if (ctype_digit(str_replace('.', '', $teks)))
                    continue;
                $labelTunggal[$no] = $teks;
                break;
            }
        }
    }

    return ['tunggal' => $labelTunggal, 'sub' => $labelSub];
}

/** Pastikan tiap item hasil selalu punya key 'sub' & 'field_ket' (kompatibel cache lama). */
function lp_normalisasi_hasil(array $hasil): array
{
    foreach ($hasil as &$item) {
        if (!array_key_exists('sub', $item)) {
            $item['sub'] = null;
        }
        if (!array_key_exists('field_ket', $item)) {
            $item['field_ket'] = null;
        }
        if (!array_key_exists('field_rujukan', $item)) {   // <<< BARU
            $item['field_rujukan'] = null;
        }
        if (!array_key_exists('field_metode', $item)) {    // <<< BARU
            $item['field_metode'] = null;
        }
    }
    unset($item);
    return $hasil;
}
/**
 * Versi revisi: dukung hasilN (tunggal) DAN hasilN_M (grup banyak nilai per No).
 * $labelDariDocx sekarang berbentuk ['tunggal' => [no=>label], 'sub' => [no=>[m=>label]]]
 */
function lp_kelompokkan_hasil(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tunggal = [];
    $grupSub = [];
    $ketGrup = [];
    $rujukanGrup = [];   // <<< BARU
    $metodeGrup = [];   // <<< BARU
    $sisa = [];

    foreach ($fields as $f) {
        if (preg_match('/^' . LP_PREFIX_HASIL . '(\d+)_(\d+)$/i', $f, $m)) {
            $grupSub[(int) $m[1]][(int) $m[2]] = $f;
        } elseif (preg_match('/^' . LP_PREFIX_HASIL . '(\d+)_ket$/i', $f, $m)) {
            $ketGrup[(int) $m[1]] = $f;
        } elseif (preg_match('/^' . LP_PREFIX_HASIL . '(\d+)_rujukan$/i', $f, $m)) {   // <<< BARU
            $rujukanGrup[(int) $m[1]] = $f;
        } elseif (preg_match('/^' . LP_PREFIX_HASIL . '(\d+)_metode$/i', $f, $m)) {    // <<< BARU
            $metodeGrup[(int) $m[1]] = $f;
        } elseif (preg_match('/^' . LP_PREFIX_HASIL . '(\d+)$/i', $f, $m)) {
            $tunggal[(int) $m[1]] = $f;
        } else {
            $sisa[] = $f;
        }
    }

    $labelTunggalDocx = $labelDariDocx['tunggal'] ?? [];
    $labelSubDocx = $labelDariDocx['sub'] ?? [];

    $semuaNo = array_unique(array_merge(array_keys($tunggal), array_keys($grupSub)));
    sort($semuaNo);

    $hasilList = [];
    foreach ($semuaNo as $no) {
        $key = 'hasil' . $no;

        if (isset($grupSub[$no])) {
            ksort($grupSub[$no]);
            $sub = [];
            foreach ($grupSub[$no] as $m => $fieldSub) {
                $subKey = $key . '_' . $m;
                $sub[] = [
                    'field' => $fieldSub,
                    'label' => $labelLama[$subKey] ?? $labelSubDocx[$no][$m] ?? "Sub Item {$no}.{$m}",
                ];
            }
            $hasilList[] = [
                'no' => $no,
                'sub' => $sub,
                'field_nilai' => null,
                'field_ket' => $ketGrup[$no] ?? null,
                'field_rujukan' => $rujukanGrup[$no] ?? null,   // <<< BARU
                'field_metode' => $metodeGrup[$no] ?? null,     // <<< BARU
                'label' => $labelLama[$key] ?? ('Item Pemeriksaan ' . $no),
            ];
        } else {
            $hasilList[] = [
                'no' => $no,
                'sub' => null,
                'field_nilai' => $tunggal[$no],
                'field_ket' => $ketGrup[$no] ?? null,
                'field_rujukan' => $rujukanGrup[$no] ?? null,   // <<< BARU
                'field_metode' => $metodeGrup[$no] ?? null,     // <<< BARU
                'label' => $labelLama[$key] ?? $labelTunggalDocx[$no] ?? ('Item Pemeriksaan ' . $no),
            ];
        }
    }

    return ['hasil' => $hasilList, 'fields' => $sisa];
}

/** Kelompokkan field ukurN / ukurN_M / ukurN_ket jadi struktur item -> sub. */
function lp_kelompokkan_ukur(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tunggal = [];
    $grupSub = [];
    $ketGrup = [];
    $sisa = [];

    foreach ($fields as $f) {
        if (preg_match('/^' . LP_PREFIX_UKUR . '(\d+)_(\d+)$/i', $f, $m)) {
            $grupSub[(int) $m[1]][(int) $m[2]] = $f;
        } elseif (preg_match('/^' . LP_PREFIX_UKUR . '(\d+)_ket$/i', $f, $m)) {
            $ketGrup[(int) $m[1]] = $f;
        } elseif (preg_match('/^' . LP_PREFIX_UKUR . '(\d+)$/i', $f, $m)) {
            $tunggal[(int) $m[1]] = $f;
        } else {
            $sisa[] = $f;
        }
    }

    $labelTunggalDocx = $labelDariDocx['tunggal'] ?? [];
    $labelSubDocx = $labelDariDocx['sub'] ?? [];

    $semuaNo = array_unique(array_merge(array_keys($tunggal), array_keys($grupSub)));
    sort($semuaNo);

    $ukurList = [];
    foreach ($semuaNo as $no) {
        $key = 'ukur' . $no;

        if (isset($grupSub[$no])) {
            ksort($grupSub[$no]);
            $sub = [];
            foreach ($grupSub[$no] as $m => $fieldSub) {
                $subKey = $key . '_' . $m;
                $sub[] = [
                    'field' => $fieldSub,
                    'label' => $labelLama[$subKey] ?? $labelSubDocx[$no][$m] ?? "Sub Item {$no}.{$m}",
                ];
            }
            $ukurList[] = [
                'no' => $no,
                'sub' => $sub,
                'field_nilai' => null,
                'field_ket' => $ketGrup[$no] ?? null,
                'label' => $labelLama[$key] ?? ('Item ' . $no),
            ];
        } else {
            $ukurList[] = [
                'no' => $no,
                'sub' => null,
                'field_nilai' => $tunggal[$no],
                'field_ket' => $ketGrup[$no] ?? null,
                'label' => $labelLama[$key] ?? $labelTunggalDocx[$no] ?? ('Item ' . $no),
            ];
        }
    }

    return ['ukur' => $ukurList, 'fields' => $sisa];
}

/** Pastikan tiap item ukur selalu punya key 'sub' & 'field_ket' (kompatibel cache lama). */
function lp_normalisasi_ukur(array $ukur): array
{
    foreach ($ukur as &$item) {
        if (!array_key_exists('sub', $item)) {
            $item['sub'] = null;
        }
        if (!array_key_exists('field_ket', $item)) {
            $item['field_ket'] = null;
        }
    }
    unset($item);
    return $ukur;
}

/**
 * Baca label "Komponen yang Diuji" untuk tiap baris ${sfdN},
 * dengan membaca sel lain di baris <w:tr> yang sama.
 * Return: ['tunggal' => [1=>'Governor', 2=>'Emergency Stop', ...], 'sub' => []]
 */
function lp_scan_label_sfd_docx(string $path): array
{
    $xml = lp_baca_xml_docx($path);
    if ($xml === '') {
        return ['tunggal' => [], 'sub' => []];
    }

    $labelTunggal = [];
    $labelSub = [];

    if (!preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return ['tunggal' => [], 'sub' => []];
    }

    foreach ($barisList[0] as $xmlBaris) {
        $teksFlat = html_entity_decode(preg_replace('/<[^>]+>/', '', $xmlBaris), ENT_QUOTES | ENT_XML1, 'UTF-8');

        // --- Baris grup: ada sfdN_M (jaga-jaga kalau nanti ada sub-item) ---
        if (preg_match('/sfd(\d+)_(\d+)\b/i', $teksFlat, $mSub)) {
            $no = (int) $mSub[1];
            preg_match_all('/sfd' . $no . '_(\d+)\b/i', $teksFlat, $mAll);
            $urutanM = array_map('intval', $mAll[1]);
            if (!$urutanM)
                continue;

            if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList))
                continue;

            foreach ($selList[0] as $xmlSel) {
                $teksSel = html_entity_decode(preg_replace('/<[^>]+>/', '', $xmlSel), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (strpos($teksSel, '{') !== false || trim($teksSel) === '')
                    continue;
                if (ctype_digit(str_replace(['.', ' '], '', trim($teksSel))))
                    continue;

                preg_match_all('/<w:p\b.*?<\/w:p>/s', $xmlSel, $paragraf);
                $baris = [];
                foreach ($paragraf[0] as $p) {
                    $t = trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $p), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if ($t !== '')
                        $baris[] = $t;
                }
                if (count($baris) < count($urutanM))
                    continue;

                foreach ($urutanM as $idx => $m) {
                    $labelSub[$no][$m] = $baris[$idx] ?? "Sub Item {$no}.{$m}";
                }
                break;
            }
            continue;
        }

        // --- Baris tunggal: sfdN biasa ---
        if (preg_match('/\bsfd(\d+)\b(?!_)/i', $teksFlat, $mNo)) {
            $no = (int) $mNo[1];
            if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList))
                continue;
            foreach ($selList[0] as $xmlSel) {
                $teks = trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $xmlSel), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($teks === '' || strpos($teks, '{') !== false)
                    continue;
                if (ctype_digit(str_replace('.', '', $teks)))
                    continue;
                $labelTunggal[$no] = $teks;
                break;
            }
        }
    }

    return ['tunggal' => $labelTunggal, 'sub' => $labelSub];
}

/** Kelompokkan field sfdN / sfdN_M / sfdN_ket jadi struktur item -> sub. */
function lp_kelompokkan_sfd(array $fields, array $labelLama = [], array $labelDariDocx = []): array
{
    $tunggal = [];
    $grupSub = [];
    $ketGrup = [];
    $sisa = [];

    foreach ($fields as $f) {
        if (preg_match('/^' . LP_PREFIX_SFD . '(\d+)_(\d+)$/i', $f, $m)) {
            $grupSub[(int) $m[1]][(int) $m[2]] = $f;
        } elseif (preg_match('/^' . LP_PREFIX_SFD . '(\d+)_ket$/i', $f, $m)) {
            $ketGrup[(int) $m[1]] = $f;
        } elseif (preg_match('/^' . LP_PREFIX_SFD . '(\d+)$/i', $f, $m)) {
            $tunggal[(int) $m[1]] = $f;
        } else {
            $sisa[] = $f;
        }
    }

    $labelTunggalDocx = $labelDariDocx['tunggal'] ?? [];
    $labelSubDocx = $labelDariDocx['sub'] ?? [];

    $semuaNo = array_unique(array_merge(array_keys($tunggal), array_keys($grupSub)));
    sort($semuaNo);

    $sfdList = [];
    foreach ($semuaNo as $no) {
        $key = 'sfd' . $no;

        if (isset($grupSub[$no])) {
            ksort($grupSub[$no]);
            $sub = [];
            foreach ($grupSub[$no] as $m => $fieldSub) {
                $subKey = $key . '_' . $m;
                $sub[] = [
                    'field' => $fieldSub,
                    'label' => $labelLama[$subKey] ?? $labelSubDocx[$no][$m] ?? "Sub Item {$no}.{$m}",
                ];
            }
            $sfdList[] = [
                'no' => $no,
                'sub' => $sub,
                'field_nilai' => null,
                'field_ket' => $ketGrup[$no] ?? null,
                'label' => $labelLama[$key] ?? ('Item ' . $no),
            ];
        } else {
            $sfdList[] = [
                'no' => $no,
                'sub' => null,
                'field_nilai' => $tunggal[$no],
                'field_ket' => $ketGrup[$no] ?? null,
                'label' => $labelLama[$key] ?? $labelTunggalDocx[$no] ?? ('Item ' . $no),
            ];
        }
    }

    return ['sfd' => $sfdList, 'fields' => $sisa];
}

/** Pastikan tiap item sfd selalu punya key 'sub' & 'field_ket' (kompatibel cache lama). */
function lp_normalisasi_sfd(array $sfd): array
{
    foreach ($sfd as &$item) {
        if (!array_key_exists('sub', $item)) {
            $item['sub'] = null;
        }
        if (!array_key_exists('field_ket', $item)) {
            $item['field_ket'] = null;
        }
    }
    unset($item);
    return $sfd;
}

/** Daftar datar semua nama field checkbox Pengukuran Tegangan. */
function lp_teg_semua_field(): array
{
    return array_map(fn($k) => 'teg_' . $k, array_keys(LP_TEG_ITEM));
}

/**
 * Pisahkan field teg_volt / teg_ampere / dst dari daftar field bebas,
 * karena field-field ini TIDAK diisi lewat input teks, melainkan lewat
 * radio 3-status (ya/tidak/na) di form.
 * ${teg_jam} SENGAJA TIDAK dimasukkan ke sini -- itu tetap input teks biasa.
 */
function lp_kelompokkan_tegangan(array $fields): array
{
    $sisa = [];
    $ditemukan = false;

    foreach ($fields as $f) {
        if (preg_match(LP_POLA_TEG, $f)) {
            $ditemukan = true; // field ini "diserap", tidak masuk daftar field bebas
            continue;
        }
        $sisa[] = $f;
    }

    if (!$ditemukan) {
        return ['tegangan' => [], 'fields' => $sisa];
    }

    $items = [];
    foreach (LP_TEG_ITEM as $key => $label) {
        $items[] = [
            'key' => $key,
            'field' => 'teg_' . $key,
            'label' => $label,
        ];
    }

    return ['tegangan' => $items, 'fields' => $sisa];
}

/**
 * Radio status -> nilai placeholder Word.
 *  ya    : "√"
 *  na    : "-"   (tidak berlaku / tidak diuji)
 *  tidak : ""    (dikosongkan / tidak dicentang)
 */
function lp_expand_tegangan_ke_field(array $tegDef, array $inputStatus): array
{
    $hasil = [];
    foreach ($tegDef as $it) {
        $st = $inputStatus[$it['key']] ?? 'tidak';
        $hasil[$it['field']] = $st === 'ya' ? '√' : ($st === 'na' ? '-' : '');
    }
    return $hasil;
}

/** Baca label komponen (sel teks pertama selain No) dari baris yang memuat ${PREFIXN_hasil}. */
function lp_scan_label_tabel_uji_docx(string $path, string $prefix): array
{
    $xml = lp_baca_xml_docx($path);
    if ($xml === '' || !preg_match_all('/<w:tr\b.*?<\/w:tr>/s', $xml, $barisList)) {
        return [];
    }
    $polos = fn(string $x) => trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $x), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    $q = preg_quote($prefix, '/');
    $label = [];

    foreach ($barisList[0] as $xmlBaris) {
        $flat = $polos($xmlBaris); // flatten dulu supaya placeholder yang terpecah run tetap terbaca
        if (!preg_match('/(?<![a-z0-9_])' . $q . '(\d+)_hasil\b/i', $flat, $m)) {
            continue;
        }
        $no = (int) $m[1];
        if (!preg_match_all('/<w:tc\b.*?<\/w:tc>/s', $xmlBaris, $selList)) {
            continue;
        }
        foreach ($selList[0] as $xmlSel) {
            $teks = $polos($xmlSel);
            if ($teks === '' || strpos($teks, '{') !== false || $teks === '-') {
                continue;
            }
            if (ctype_digit(str_replace('.', '', $teks))) { // sel "No"
                continue;
            }
            $label[$no] = $teks;
            break;
        }
    }
    return $label;
}

/** Scan label untuk ketiga tabel sekaligus. */
function lp_scan_label_tabel_uji_semua(string $path): array
{
    $hasil = [];
    foreach (array_keys(LP_TABEL_UJI_DEF) as $prefix) {
        $hasil[$prefix] = lp_scan_label_tabel_uji_docx($path, $prefix);
    }
    return $hasil;
}

/** Kelompokkan field PREFIXN_hasil + PREFIXN_(ket|nab) jadi daftar item. */
function lp_kelompokkan_tabel_uji(array $fields, string $prefix, array $labelLama = [], array $labelDocx = []): array
{
    $kol2 = LP_TABEL_UJI_DEF[$prefix]['kolom2_key'];
    $pola = '/^' . preg_quote($prefix, '/') . '(\d+)_(hasil|' . preg_quote($kol2, '/') . ')$/i';

    $tmp = [];
    $sisa = [];
    foreach ($fields as $f) {
        if (preg_match($pola, $f, $m)) {
            $tmp[(int) $m[1]][strtolower($m[2])] = $f;
        } else {
            $sisa[] = $f;
        }
    }
    ksort($tmp);

    $items = [];
    foreach ($tmp as $no => $g) {
        if (empty($g['hasil'])) { // hanya ada kolom ke-2 -> kembalikan jadi field biasa
            foreach ($g as $f) {
                $sisa[] = $f;
            }
            continue;
        }
        $items[] = [
            'no' => $no,
            'field_hasil' => $g['hasil'],
            'field_kol2' => $g[$kol2] ?? null,
            'label' => $labelDocx[$no] ?? $labelLama[$no] ?? ('Item ' . $no),
        ];
    }
    return ['items' => $items, 'fields' => $sisa];
}

/** Hasil kosong -> "-", angka murni + satuan (dB), kolom ke-2 kosong -> sesuai konfigurasi. */
function lp_terapkan_tabel_uji(array $data, array $hasilFields): array
{
    foreach (LP_TABEL_UJI_DEF as $prefix => $def) {
        foreach (($hasilFields[$prefix] ?? []) as $it) {
            $fh = $it['field_hasil'] ?? null;
            if ($fh) {
                $v = trim((string) ($data[$fh] ?? ''));
                if ($v === '') {
                    $v = '-';
                } elseif ($def['satuan_hasil'] !== '' && preg_match('/^[\d.,]+$/', $v)) {
                    $v .= ' ' . $def['satuan_hasil'];
                }
                $data[$fh] = $v;
            }
            $f2 = $it['field_kol2'] ?? null;
            if ($f2) {
                $v = trim((string) ($data[$f2] ?? ''));
                $data[$f2] = $v === '' ? $def['kol2_kosong'] : $v;
            }
        }
    }
    return $data;
}

/** Hasil scan -> struktur fields_json siap simpan (dengan label default). */
// signature: tambah parameter paling akhir
function lp_build_fields_dengan_label(
    array $hasilScan,
    array $fieldsLamaJson = [],
    array $labelChecklistDocx = [],
    array $labelHasilDocx = [],
    array $labelVisualDocx = [],
    array $labelDimensiDocx = [],
    array $labelKetelDocx = [],
    array $labelCkDocx = [],
    array $labelUkurDocx = [],
    array $labelSfdDocx = [],
    array $labelVfDocx = [],
    array $labelDcpDocx = [],
    array $labelTabelUjiDocx = [],
    array $labelPvfDocx = []               // <<< BARU
): array {
    $labelLama = function (array $items): array {
        $peta = [];
        foreach ($items as $it) {
            if (isset($it['field'], $it['label'])) {
                $peta[$it['field']] = $it['label'];
            }
        }
        return $peta;
    };
    $petaFields = $labelLama($fieldsLamaJson['fields'] ?? []);
    $petaTabel = $labelLama($fieldsLamaJson['table_fields'] ?? []);

    $petaChecklistLama = [];
    foreach (($fieldsLamaJson['checklist'] ?? []) as $c) {
        if (isset($c['no'], $c['label'])) {
            $petaChecklistLama['cek' . $c['no']] = $c['label'];
        }
    }
    $petaHasilLama = [];
    foreach (($fieldsLamaJson['hasil'] ?? []) as $h) {
        if (isset($h['no'], $h['label'])) {
            $petaHasilLama['hasil' . $h['no']] = $h['label'];
        }
    }

    // <<< BARU: peta label visual lama
    $petaVisualLama = ['grup' => [], 'item' => []];
    foreach (($fieldsLamaJson['visual'] ?? []) as $g) {
        $petaVisualLama['grup'][$g['kode']] = $g['judul'];
        foreach ($g['items'] as $it) {
            $petaVisualLama['item'][$g['kode'] . '_' . $it['sub']] = $it['label'];
        }
    }

    $petaDimensiLama = ['grup' => [], 'item' => []];
    foreach (($fieldsLamaJson['dimensi'] ?? []) as $g) {
        $petaDimensiLama['grup'][$g['no']] = $g['judul'];
        foreach ($g['items'] as $it) {
            $petaDimensiLama['item'][$g['no'] . '_' . $it['sub']] = $it['label'];
        }
    }

    $petaKetelLama = ['grup' => [], 'item' => []];
    foreach (($fieldsLamaJson['ketel_visual'] ?? []) as $g) {
        $petaKetelLama['grup'][$g['no']] = $g['judul'];
        foreach ($g['items'] as $it) {
            $petaKetelLama['item'][$g['no'] . '_' . $it['urut']] = $it['label'];
        }
    }

    $petaCkLama = ['grup' => [], 'item' => []];
    foreach (($fieldsLamaJson['ck'] ?? []) as $g) {
        $petaCkLama['grup'][$g['kode']] = $g['judul'];
        foreach ($g['items'] as $it) {
            $petaCkLama['item'][$g['kode'] . '_' . $it['no']] = $it['label'];
        }
    }

    $petaUkurLama = [];
    foreach (($fieldsLamaJson['ukur'] ?? []) as $u) {
        if (isset($u['no'], $u['label'])) {
            $petaUkurLama['ukur' . $u['no']] = $u['label'];
        }
    }

    $petaSfdLama = [];
    foreach (($fieldsLamaJson['sfd'] ?? []) as $u) {
        if (isset($u['no'], $u['label'])) {
            $petaSfdLama['sfd' . $u['no']] = $u['label'];
        }
    }

    $petaVfLama = ['grup' => [], 'item' => []];
    foreach (($fieldsLamaJson['vf'] ?? []) as $g) {
        $petaVfLama['grup'][$g['no']] = $g['judul'];
        foreach ($g['items'] as $it) {
            $petaVfLama['item'][$g['no'] . '_' . $it['urut']] = [
                'lokasi' => $it['lokasi'] ?? '',
                'komponen' => $it['komponen'] ?? '',
                'label' => $it['label'] ?? '',
            ];
        }
    }

    $petaPvfLama = ['grup' => [], 'item' => []];
    foreach (($fieldsLamaJson['pvf'] ?? []) as $g) {
        $petaPvfLama['grup'][$g['no']] = $g['judul'];
        foreach ($g['items'] as $it) {
            $petaPvfLama['item'][$g['no'] . '_' . $it['urut']] = [
                'lokasi' => $it['lokasi'] ?? '',
                'komponen' => $it['komponen'] ?? '',
                'label' => $it['label'] ?? '',
            ];
        }
    }

    $petaDcpLama = ['grup' => [], 'item' => []];
    foreach (($fieldsLamaJson['dcp'] ?? []) as $g) {
        $petaDcpLama['grup'][$g['no']] = $g['label'] ?? '';
        foreach (($g['subs'] ?? []) as $s) {
            $petaDcpLama['item'][$g['no'] . '_' . $s['sub']] = $s['label'];
        }
    }

    $petaTabelUjiLama = [];
    foreach (array_keys(LP_TABEL_UJI_DEF) as $p) {
        foreach (($fieldsLamaJson[$p] ?? []) as $it) {
            if (isset($it['no'], $it['label'])) {
                $petaTabelUjiLama[$p][$it['no']] = $it['label'];
            }
        }
    }


    $kelompokCek = lp_kelompokkan_checklist($hasilScan['fields'] ?? [], $petaChecklistLama, $labelChecklistDocx);
    $kelompokVisual = lp_kelompokkan_visual($kelompokCek['fields'], $petaVisualLama, $labelVisualDocx);
    $kelompokCk = lp_kelompokkan_ck($kelompokVisual['fields'], $petaCkLama, $labelCkDocx);
    $kelompokUkur = lp_kelompokkan_ukur($kelompokCk['fields'], $petaUkurLama, $labelUkurDocx);
    $kelompokSfd = lp_kelompokkan_sfd($kelompokUkur['fields'], $petaSfdLama, $labelSfdDocx);
    $kelompokTeg = lp_kelompokkan_tegangan($kelompokSfd['fields']);
    $kelompokVf = lp_kelompokkan_vf($kelompokTeg['fields'], $petaVfLama, $labelVfDocx);
    $kelompokDcp = lp_kelompokkan_dcp($kelompokVf['fields'], $petaDcpLama, $labelDcpDocx);
    $kelompokPgk = lp_kelompokkan_tabel_uji($kelompokDcp['fields'], 'pgk', $petaTabelUjiLama['pgk'] ?? [], $labelTabelUjiDocx['pgk'] ?? []);
    $kelompokSaf = lp_kelompokkan_tabel_uji($kelompokPgk['fields'], 'saf', $petaTabelUjiLama['saf'] ?? [], $labelTabelUjiDocx['saf'] ?? []);
    $kelompokBis = lp_kelompokkan_tabel_uji($kelompokSaf['fields'], 'bis', $petaTabelUjiLama['bis'] ?? [], $labelTabelUjiDocx['bis'] ?? []);
    $kelompokPvf = lp_kelompokkan_pvf($kelompokBis['fields'], $petaPvfLama, $labelPvfDocx);           // <<< BARU
    $kelompokKetel = lp_kelompokkan_ketel_visual($kelompokPvf['fields'], $petaKetelLama, $labelKetelDocx); // <<< sumber diganti
    $kelompokDimensi = lp_kelompokkan_dimensi($kelompokKetel['fields'], $petaDimensiLama, $labelDimensiDocx);
    $kelompokHasil = lp_kelompokkan_hasil($kelompokDimensi['fields'], $petaHasilLama, $labelHasilDocx);


    $buat = function (array $namaField, array $peta): array {
        $hasil = [];
        foreach ($namaField as $f) {
            $hasil[] = ['field' => $f, 'label' => $peta[$f] ?? lp_label_dari_field($f)];
        }
        return $hasil;
    };

    return [
        'fields' => $buat($kelompokHasil['fields'], $petaFields),
        'table_fields' => $buat($hasilScan['table_fields'] ?? [], $petaTabel),
        'checklist' => $kelompokCek['checklist'],
        'hasil' => $kelompokHasil['hasil'],
        'visual' => $kelompokVisual['visual'],
        'dimensi' => $kelompokDimensi['dimensi'],
        'ketel_visual' => $kelompokKetel['ketel_visual'],
        'ck' => $kelompokCk['ck'],
        'ukur' => $kelompokUkur['ukur'],
        'sfd' => $kelompokSfd['sfd'],
        'tegangan' => $kelompokTeg['tegangan'],
        'vf' => $kelompokVf['vf'],
        'dcp' => $kelompokDcp['dcp'],
        'pgk' => $kelompokPgk['items'],
        'saf' => $kelompokSaf['items'],
        'bis' => $kelompokBis['items'],
        'pvf' => $kelompokPvf['pvf'],   // <<< BARU
    ];
}

/**
 * Lengkapi array checklist dari CACHE LAMA (fields_json sebelum fitur
 * "keterangan gabungan/merge" ditambahkan) supaya selalu punya key
 * 'field_ket', 'ket_span', 'ket_tampilkan' -- mencegah undefined array key
 * di tempat manapun checklist ini dipakai (form, generate docx, dll).
 */
function lp_normalisasi_checklist(array $checklist): array
{
    foreach ($checklist as &$item) {
        if (!array_key_exists('field_ket', $item)) {
            $item['field_ket'] = null;
        }
        if (!array_key_exists('ket_tampilkan', $item)) {
            // Cache lama: tiap baris punya keterangan sendiri (tidak ada merge)
            $item['ket_tampilkan'] = $item['field_ket'] !== null;
        }
        if (!array_key_exists('ket_span', $item)) {
            $item['ket_span'] = $item['field_ket'] !== null ? 1 : null;
        }
    }
    unset($item);
    return $checklist;
}

/**
 * Ambil daftar field untuk SATU Template_Laporan.
 * Pakai cache fields_json di DB; kalau cache kosong (mis. template lama yang
 * di-scan pakai fungsi modul Surat dan gagal karena cek ekstensi), otomatis
 * scan ULANG file aslinya dari Drive lalu cache-nya diperbarui.
 */
function lp_muat_fields_template(PDO $pdo, array $templateRow): array
{
    $decoded = !empty($templateRow['fields_json'])
        ? (json_decode($templateRow['fields_json'], true) ?: [])
        : [];

    $hasil = [
        'fields' => $decoded['fields'] ?? [],
        'table_fields' => $decoded['table_fields'] ?? [],
        'checklist' => lp_normalisasi_checklist($decoded['checklist'] ?? []),
        'hasil' => lp_normalisasi_hasil($decoded['hasil'] ?? []),
        'visual' => $decoded['visual'] ?? [],
        'dimensi' => $decoded['dimensi'] ?? [],
        'ketel_visual' => $decoded['ketel_visual'] ?? [],
        'ck' => $decoded['ck'] ?? [],
        'ukur' => lp_normalisasi_ukur($decoded['ukur'] ?? []),
        'sfd' => lp_normalisasi_sfd($decoded['sfd'] ?? []),
        'tegangan' => $decoded['tegangan'] ?? [],
        'vf' => $decoded['vf'] ?? [],
        'dcp' => $decoded['dcp'] ?? [],
        'pgk' => $decoded['pgk'] ?? [],   // <<< BARU
        'saf' => $decoded['saf'] ?? [],
        'bis' => $decoded['bis'] ?? [],
        'pvf' => $decoded['pvf'] ?? [],
    ];

    $cacheKosong = empty($hasil['fields']) && empty($hasil['table_fields'])
        && empty($hasil['checklist']) && empty($hasil['hasil']) && empty($hasil['visual'])
        && empty($hasil['dimensi']) && empty($hasil['ketel_visual']) && empty($hasil['ck'])
        && empty($hasil['ukur']) && empty($hasil['sfd']) && empty($hasil['tegangan'])
        && empty($hasil['vf'])
        && empty($hasil['vf']) && empty($hasil['dcp'])
        && empty($hasil['pgk']) && empty($hasil['saf']) && empty($hasil['bis'])
        && empty($hasil['pvf']);
    $bisaScanUlang = !empty($templateRow['drive_file_id']) && ($templateRow['format'] ?? '') === 'word_pdf';

    if (!$cacheKosong || !$bisaScanUlang) {
        return lp_finalisasi_fields($hasil);
    }

    try {
        return lp_dengan_template_sementara(
            $templateRow['drive_file_id'],
            function ($pathLokal) use ($pdo, $templateRow, $decoded) {
                $labelChecklistDocx = lp_scan_label_checklist_docx($pathLokal);
                $labelHasilDocx = lp_scan_label_hasil_docx($pathLokal);
                $labelVisualDocx = lp_scan_label_visual_docx($pathLokal);
                $labelDimensiDocx = lp_scan_label_dimensi_docx($pathLokal);
                $labelKetelDocx = lp_scan_label_ketel_docx($pathLokal);
                $labelCkDocx = lp_scan_label_ck_docx($pathLokal);
                $labelUkurDocx = lp_scan_label_ukur_docx($pathLokal);   // <<< BARU
                $labelSfdDocx = lp_scan_label_sfd_docx($pathLokal);
                $labelVfDocx = lp_scan_label_vf_docx($pathLokal);
                $labelDcpDocx = lp_scan_label_dcp_docx($pathLokal);
                $labelTabelUjiDocx = lp_scan_label_tabel_uji_semua($pathLokal);
                $labelPvfDocx = lp_scan_label_pvf_docx($pathLokal);

                $baru = lp_build_fields_dengan_label(
                    lp_scan_placeholder_docx($pathLokal),
                    $decoded,
                    $labelChecklistDocx,
                    $labelHasilDocx,
                    $labelVisualDocx,
                    $labelDimensiDocx,
                    $labelKetelDocx,
                    $labelCkDocx,
                    $labelUkurDocx,
                    $labelSfdDocx,
                    $labelVfDocx,
                    $labelDcpDocx,
                    $labelTabelUjiDocx,
                    $labelPvfDocx          // <<< BARU
                );
                if (
                    !empty($baru['fields']) || !empty($baru['table_fields']) || !empty($baru['checklist'])
                    || !empty($baru['hasil']) || !empty($baru['visual']) || !empty($baru['dimensi'])
                    || !empty($baru['ketel_visual']) || !empty($baru['ck']) || !empty($baru['ukur'])
                    || !empty($baru['sfd'])
                    || !empty($baru['vf'])
                    || !empty($baru['dcp'])
                    || !empty($baru['pgk']) || !empty($baru['saf']) || !empty($baru['bis'])
                    || !empty($baru['pvf'])   // <<< BARU
                ) {
                    try {
                        $pdo->prepare("UPDATE Template_Laporan SET fields_json = ? WHERE id = ?")
                            ->execute([json_encode($baru, JSON_UNESCAPED_UNICODE), (int) $templateRow['id']]);
                    } catch (\Throwable $e) {
                    }
                }
                return lp_finalisasi_fields($baru);
            }
        );
    } catch (\Throwable $e) {
        return lp_finalisasi_fields($hasil);
    }
}


/**
 * Scan ULANG paksa satu Template_Laporan dari file Drive-nya yang terbaru,
 * lalu simpan hasilnya ke fields_json (menimpa cache lama).
 *
 * Beda dengan lp_muat_fields_template(): fungsi itu HANYA scan ulang kalau
 * cache kosong. Fungsi ini dipakai saat admin menekan tombol "Sinkronkan"
 * setelah mengedit template di Word/Google Docs -- supaya field baru yang
 * ditambahkan (atau dihapus) di dokumen langsung kebaca ulang.
 *
 * Label yang sudah pernah diedit admin sebelumnya (field & checklist & hasil)
 * tetap dipertahankan; hanya placeholder yang sudah tidak ada lagi di dokumen
 * yang otomatis hilang dari daftar.
 */
function lp_scan_ulang_template(PDO $pdo, array $templateRow): array
{
    if (empty($templateRow['drive_file_id'])) {
        throw new RuntimeException('Template belum terhubung ke Google Drive.');
    }
    if (($templateRow['format'] ?? '') !== 'word_pdf') {
        throw new RuntimeException('Hanya template Word (.docx) yang bisa disinkronkan otomatis.');
    }

    $labelLama = !empty($templateRow['fields_json'])
        ? (json_decode($templateRow['fields_json'], true) ?: [])
        : [];

    return lp_dengan_template_sementara(
        $templateRow['drive_file_id'],
        function ($pathLokal) use ($pdo, $templateRow, $labelLama) {
            $labelChecklistDocx = lp_scan_label_checklist_docx($pathLokal);
            $labelHasilDocx = lp_scan_label_hasil_docx($pathLokal);
            $labelVisualDocx = lp_scan_label_visual_docx($pathLokal);
            $labelDimensiDocx = lp_scan_label_dimensi_docx($pathLokal);
            $labelKetelDocx = lp_scan_label_ketel_docx($pathLokal);   // <<< BARU
            $labelCkDocx = lp_scan_label_ck_docx($pathLokal);   // <<< BARU
            $labelUkurDocx = lp_scan_label_ukur_docx($pathLokal);
            $labelSfdDocx = lp_scan_label_sfd_docx($pathLokal);
            $labelVfDocx = lp_scan_label_vf_docx($pathLokal);     // <<< BUG FIX (sebelumnya tidak ada)
            $labelDcpDocx = lp_scan_label_dcp_docx($pathLokal);   // <<< BARU
            $labelTabelUjiDocx = lp_scan_label_tabel_uji_semua($pathLokal);
            $labelPvfDocx = lp_scan_label_pvf_docx($pathLokal);  // <<< BARU
    
            $baru = lp_build_fields_dengan_label(
                lp_scan_placeholder_docx($pathLokal),
                $labelLama,
                $labelChecklistDocx,
                $labelHasilDocx,
                $labelVisualDocx,
                $labelDimensiDocx,
                $labelKetelDocx,
                $labelCkDocx,
                $labelUkurDocx,
                $labelSfdDocx,
                $labelVfDocx,
                $labelDcpDocx,
                $labelTabelUjiDocx,
                $labelPvfDocx        // <<< BARU
            );

            $pdo->prepare("UPDATE Template_Laporan SET fields_json = ? WHERE id = ?")
                ->execute([json_encode($baru, JSON_UNESCAPED_UNICODE), (int) $templateRow['id']]);

            return lp_finalisasi_fields($baru);
        }
    );
}

/* =========================================================
 * NOMOR LAPORAN OTOMATIS
 * Format: 001/{KODE_LAPORAN}/LP-K3/{bulan romawi}/{tahun}, reset tiap tahun.
 * ========================================================= */

function lp_bulan_romawi(?int $bulan = null): string
{
    $bulan = $bulan ?? (int) date('n');
    return ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$bulan - 1];
}

function lp_nomor_tertinggi(PDO $pdo, string $kodeLaporan, int $tahun): int
{
    $kodeLaporan = strtoupper(trim($kodeLaporan));
    if ($kodeLaporan === '') {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT nomor_laporan FROM Laporan_Pemeriksaan WHERE nomor_laporan LIKE ?");
    $stmt->execute(['%/' . $kodeLaporan . '/%']);

    $tahunStr = (string) $tahun;
    $tertinggi = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $nomor) {
        $segmen = array_map('trim', explode('/', (string) $nomor));
        if (count($segmen) < 2 || !ctype_digit($segmen[0] ?? '')) {
            continue; // nomor lama hasil upload manual, lewati
        }
        if (!in_array($kodeLaporan, $segmen, true) || !in_array($tahunStr, $segmen, true)) {
            continue;
        }
        $tertinggi = max($tertinggi, (int) $segmen[0]);
    }
    return $tertinggi;
}

function lp_preview_nomor(PDO $pdo, string $kodeLaporan, ?string $noUrutManual = null): string
{
    $kodeLaporan = strtoupper(trim($kodeLaporan)) ?: 'LP';
    $tahun = (int) date('Y');
    $noUrutManual = trim((string) $noUrutManual);
    $counter = ($noUrutManual !== '' && ctype_digit($noUrutManual))
        ? (int) $noUrutManual
        : lp_nomor_tertinggi($pdo, $kodeLaporan, $tahun) + 1;

    return sprintf('%03d/%s/ARP/%s/%d', $counter, $kodeLaporan, lp_bulan_romawi(), $tahun);
}

function lp_generate_nomor(PDO $pdo, string $kodeLaporan, ?string $noUrutManual = null): string
{
    $noUrutManual = trim((string) $noUrutManual);
    if ($noUrutManual !== '' && !ctype_digit($noUrutManual)) {
        throw new RuntimeException("Nomor urut laporan harus berupa angka (contoh: 005).");
    }

    $nomor = lp_preview_nomor($pdo, $kodeLaporan, $noUrutManual);

    $cek = $pdo->prepare("SELECT id FROM Laporan_Pemeriksaan WHERE nomor_laporan = ?");
    $cek->execute([$nomor]);
    if ($cek->fetch()) {
        throw new RuntimeException("Nomor laporan \"{$nomor}\" sudah dipakai. Gunakan nomor urut manual lain, atau kosongkan untuk otomatis.");
    }
    return $nomor;
}

/* =========================================================
 * GENERATE FILE LAPORAN (.docx) DARI TEMPLATE
 * ========================================================= */

/** Cari penulisan ASLI sebuah placeholder (biar ${Nama_Pemilik} tetap kena). */
function lp_cari_macro_asli(string $teksPolos, string $namaField): ?string
{
    if ($teksPolos === '') {
        return null;
    }
    $q = preg_quote($namaField, '/');
    if (
        preg_match('/\$\{\s*(' . $q . ')\s*\}/i', $teksPolos, $m)
        || preg_match('/(?<!\$)\{\s*(' . $q . ')\s*\}/i', $teksPolos, $m)
    ) {
        return $m[1];
    }
    return null;
}

/** Isi placeholder gaya {nama} (tanpa dolar) langsung di XML mentah hasil generate. */
function lp_isi_placeholder_kurung(string $docxPath, array $fields): void
{
    $zip = new ZipArchive();
    if ($zip->open($docxPath) !== true) {
        return;
    }
    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) {
        $zip->close();
        return;
    }
    // Rapikan {nama} yang terpecah antar-run Word
    $xml = preg_replace_callback('/\{[^{}]*\}/U', function ($m) {
        $blok = $m[0];
        if (preg_match('#</?w:(p|tbl|tr|tc|br|drawing|pict|sectPr)\b#', $blok))
            return $blok;
        if (strlen($blok) > 1500)
            return $blok;
        $teks = strip_tags($blok);
        return preg_match('/^\{\s*[a-zA-Z0-9_]+\s*\}$/', $teks) ? $teks : $blok;
    }, $xml);
    foreach ($fields as $key => $value) {
        $xml = str_replace('{' . $key . '}', htmlspecialchars((string) $value, ENT_QUOTES), $xml);
    }
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
}

/** Baca HANYA word/document.xml (tanpa header/footer) -- dipakai untuk
 *  memodifikasi isi tabel body dokumen secara langsung. */
function lp_baca_document_xml(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    return $xml !== false ? $xml : '';
}

/** Salin file template ke lokasi sementara dengan word/document.xml
 *  diganti $xmlBaru. */
function lp_salin_dengan_document_xml(string $templatePath, string $xmlBaru): string
{
    $tmpPath = tempnam(sys_get_temp_dir(), 'lp_tpl_') . '.docx';
    if (!copy($templatePath, $tmpPath)) {
        throw new RuntimeException('Gagal menyiapkan salinan sementara template laporan.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        @unlink($tmpPath);
        throw new RuntimeException('Gagal membuka salinan sementara template laporan.');
    }
    $zip->addFromString('word/document.xml', $xmlBaru);
    $zip->close();
    return $tmpPath;
}

/**
 * Klon SEKELOMPOK baris tabel (bisa >1 <w:tr> per item), bukan cuma satu
 * baris seperti TemplateProcessor::cloneRow() bawaan.
 *
 * Kenapa perlu: kalau template menaruh sebagian kolom (mis. ${item_alat})
 * di <w:tr> BERIKUTNYA (baris kedua per-item, biasanya karena sel lain
 * di-vMerge ke bawah -- lihat contoh "Alat" di template grounding), baris
 * itu tidak pernah ikut digandakan oleh cloneRow() bawaan -> nilainya
 * nyangkut sama untuk semua item.
 *
 * Fungsi ini mendeteksi baris anchor (mis. ${item_no}) lalu mengikutkan
 * baris-baris SESUDAHNYA selama baris tsb masih memuat placeholder
 * ${item_xxx} lain (dan bukan baris anchor baru). Penomoran #1, #2, dst
 * yang dihasilkan KONSISTEN dengan skema cloneRow bawaan, jadi kode
 * setValue('item_alat#'.$baris, ...) yang sudah ada tidak perlu diubah.
 */
function lp_clone_blok_baris_item(string $xml, string $anchorMacro, int $count): string
{
    $posAnchor = strpos($xml, '${' . $anchorMacro . '}');
    if ($posAnchor === false) {
        return $xml;
    }

    // PENTING: cari "<w:tr" yang benar-benar tag PEMBUKA BARIS (diikuti '>' atau
    // spasi), BUKAN "<w:trPr>"/"<w:trHeight>" dsb yang kebetulan juga diawali
    // "<w:tr". Pakai regex dengan \b, jangan strrpos() string biasa -- kalau
    // tidak, titik awal blok bisa nyangkut di tengah tag <w:trPr> dan
    // menghasilkan XML yang rusak (file jadi tidak bisa dibuka).
    if (!preg_match_all('/<w:tr\b/', substr($xml, 0, $posAnchor), $mAwal, PREG_OFFSET_CAPTURE)) {
        return $xml;
    }
    $mulaiBlok = end($mAwal[0])[1];

    $akhirTagAnchor = strpos($xml, '</w:tr>', $posAnchor);
    if ($akhirTagAnchor === false) {
        return $xml;
    }
    $akhirBlok = $akhirTagAnchor + strlen('</w:tr>');

    while (preg_match('/\G<w:tr\b/', $xml, $m, 0, $akhirBlok)) {
        $akhirTagBerikut = strpos($xml, '</w:tr>', $akhirBlok);
        if ($akhirTagBerikut === false) {
            break;
        }
        $akhirBarisBerikut = $akhirTagBerikut + strlen('</w:tr>');
        $teksBarisBerikut = substr($xml, $akhirBlok, $akhirBarisBerikut - $akhirBlok);

        if (strpos($teksBarisBerikut, '${' . $anchorMacro . '}') !== false) {
            break;
        }
        if (!preg_match('/\$\{item_[a-zA-Z0-9_]+\}/', $teksBarisBerikut)) {
            break;
        }
        $akhirBlok = $akhirBarisBerikut;
    }

    $blokAsli = substr($xml, $mulaiBlok, $akhirBlok - $mulaiBlok);

    $hasil = '';
    for ($i = 1; $i <= $count; $i++) {
        $hasil .= preg_replace_callback(
            '/\$\{([a-zA-Z0-9_]+)\}/',
            fn($m) => '${' . $m[1] . '#' . $i . '}',
            $blokAsli
        );
    }

    return substr($xml, 0, $mulaiBlok) . $hasil . substr($xml, $akhirBlok);
}

function lp_generate_docx(
    string $templatePath,
    array $dataForm,
    array $items,
    string $nomorLaporan,
    string $namaTemplate,
    string $namaPerusahaan = '',
    array $ndtRows = [],
    array $pujRows = [],
    array $pjnRows = []
): string {
    if (!is_file($templatePath)) {
        throw new RuntimeException("File template laporan tidak ditemukan.");
    }

    $templateNdtSementara = null;

    // >>> NDT: klon blok baris (data + foto) SEBELUM TemplateProcessor dibuat
    $xmlKerja = lp_baca_document_xml($templatePath);
    $perluTulisUlang = false;

    if ($xmlKerja !== '' && strpos($xmlKerja, '${' . LP_ANCHOR_NDT . '}') !== false) {
        $jumlahNdt = !empty($ndtRows) ? count($ndtRows) : 1;
        $xmlKerja = lp_clone_blok_baris_ndt($xmlKerja, $jumlahNdt);
        $perluTulisUlang = true;
        if (empty($ndtRows)) {
            $ndtRows = [['bagian' => '-', 'lokasi' => '-', 'ada' => '-', 'tidak' => '-', 'ket' => '-', 'foto1_path' => null, 'foto2_path' => null]];
        }
    }

    if ($xmlKerja !== '' && strpos($xmlKerja, '${' . LP_ANCHOR_PUJ . '}') !== false) {
        $jumlahPuj = !empty($pujRows) ? count($pujRows) : 1;
        $xmlKerja = lp_clone_blok_satu_baris($xmlKerja, LP_ANCHOR_PUJ, $jumlahPuj);
        $perluTulisUlang = true;
        if (empty($pujRows)) {
            $pujRows = [['tinggi_angkat' => '-', 'beban' => '-', 'kecepatan' => '-', 'gerakan' => '-', 'hasil' => '-', 'ket' => '-']];
        }
    }

    if ($xmlKerja !== '' && strpos($xmlKerja, '${' . LP_ANCHOR_PJN . '}') !== false) {
        $jumlahPjn = !empty($pjnRows) ? count($pjnRows) : 1;
        $xmlKerja = lp_clone_blok_satu_baris($xmlKerja, LP_ANCHOR_PJN, $jumlahPjn);
        $perluTulisUlang = true;
        if (empty($pjnRows)) {
            $pjnRows = [['fungsi' => '-', 'tinggi_angkat' => '-', 'kecepatan' => '-', 'gerakan' => '-', 'beban' => '-', 'hasil' => '-', 'ket' => '-']];
        }
    }

    if ($perluTulisUlang) {
        $templatePath = lp_salin_dengan_document_xml($templatePath, $xmlKerja);
        $templateSementara = $templatePath;
    }

    $processor = new TemplateProcessor($templatePath);
    $teksPolos = lp_teks_polos_docx($templatePath);

    // ----- Tabel item berulang -----
    // CATATAN "alat": kolom ini SENGAJA TIDAK ikut di-clone per baris.
    // Di template, ${item_alat} cuma ada SATU baris untuk semua titik
    // pengukuran (lihat contoh grounding: "Alat Menggunakan Earth Tester
    // Merk Kyoritsu" cuma sekali di bawah, bukan berulang per titik).
    // Makanya field ini diisi TERPISAH, SEKALI SAJA (tanpa suffix #N),
    // di luar loop per-item di bawah.
    if (!empty($items)) {
        $kolomPertama = array_key_first((array) reset($items));
        $kandidatAnchor = array_values(array_unique(array_filter([
            LP_ANCHOR_KOLOM_TABEL,
            $kolomPertama !== null ? LP_PREFIX_KOLOM_TABEL . $kolomPertama : null,
        ])));

        $anchorDipakai = null;
        foreach ($kandidatAnchor as $kandidat) {
            try {
                $processor->cloneRow($kandidat, count($items));
                $anchorDipakai = $kandidat;
                break;
            } catch (\Throwable $e) {
                continue;
            }
        }

        if ($anchorDipakai !== null) {
            foreach (array_values($items) as $i => $item) {
                $baris = $i + 1;
                try {
                    $processor->setValue(LP_ANCHOR_KOLOM_TABEL . '#' . $baris, $baris);
                } catch (\Throwable $e) {
                }
                foreach ($item as $namaKolom => $nilai) {
                    if ($namaKolom === 'alat') {
                        continue; // ditangani terpisah di bawah, cuma sekali untuk seluruh tabel
                    }
                    try {
                        $processor->setValue(
                            LP_PREFIX_KOLOM_TABEL . $namaKolom . '#' . $baris,
                            htmlspecialchars((string) $nilai, ENT_QUOTES)
                        );
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

        // Isi ${item_alat} SEKALI (tanpa suffix #N) -- ambil nilai TERAKHIR
        // yang diisi user (konsisten dengan JS "Tambah Baris" yang membawa
        // nilai alat dari baris terakhir ke baris baru).
        $nilaiAlat = '';
        foreach ($items as $item) {
            if (!empty($item['alat'])) {
                $nilaiAlat = $item['alat'];
            }
        }
        if ($nilaiAlat !== '') {
            try {
                $processor->setValue(
                    LP_PREFIX_KOLOM_TABEL . 'alat',
                    htmlspecialchars((string) $nilaiAlat, ENT_QUOTES)
                );
            } catch (\Throwable $e) {
            }
        }
    }
    // >>> TAMBAHAN: Isi ${item_tahanan} SEKALI (tanpa suffix #N) -- untuk
    // paragraf narasi "a. Hasil pengukuran tahanan pembumian..." yang ada
    // DI LUAR tabel item. Ambil nilai TERAKHIR yang diisi user, sama seperti
    // pola item_alat di atas.
    $nilaiTahananNarasi = '';
    foreach ($items as $item) {
        if (!empty($item['tahanan'])) {
            $nilaiTahananNarasi = $item['tahanan'];
        }
    }
    if ($nilaiTahananNarasi !== '') {
        try {
            $processor->setValue(
                LP_PREFIX_KOLOM_TABEL . 'tahanan',
                htmlspecialchars((string) $nilaiTahananNarasi, ENT_QUOTES)
            );
        } catch (\Throwable $e) {
        }
    }

    // ----- Field biasa -----
    $fields = array_merge($dataForm, ['nomor' => $nomorLaporan, 'nomor_laporan' => $nomorLaporan]);

    // Field multi-baris disiapkan lebih dulu jadi XML siap-pakai
    foreach ($fields as $kMulti => $vMulti) {
        if (lp_is_field_multiline((string) $kMulti)) {
            $fields[$kMulti] = lp_nilai_multiline_ke_xml((string) $vMulti);
        }
    }

    foreach ($fields as $key => $value) {
        $macro = lp_cari_macro_asli($teksPolos, $key) ?? $key;
        $sudahXml = lp_is_field_multiline((string) $key);
        try {
            $processor->setValue($macro, $sudahXml ? $value : htmlspecialchars((string) $value, ENT_QUOTES));
        } catch (\Throwable $e) {
        }
    }

    foreach ($ndtRows as $i => $row) {
        $baris = $i + 1;
        try {
            $processor->setValue('ndt_no#' . $baris, $baris);
        } catch (\Throwable $e) {
        }
        try {
            $processor->setValue('ndt_bagian#' . $baris, htmlspecialchars($row['bagian'], ENT_QUOTES));
        } catch (\Throwable $e) {
        }
        try {
            $processor->setValue('ndt_lokasi#' . $baris, htmlspecialchars($row['lokasi'], ENT_QUOTES));
        } catch (\Throwable $e) {
        }
        try {
            $processor->setValue('ndt_ada#' . $baris, $row['ada']);
        } catch (\Throwable $e) {
        }
        try {
            $processor->setValue('ndt_tidak#' . $baris, $row['tidak']);
        } catch (\Throwable $e) {
        }
        try {
            $processor->setValue('ndt_ket#' . $baris, htmlspecialchars($row['ket'], ENT_QUOTES));
        } catch (\Throwable $e) {
        }

        foreach (['foto1', 'foto2'] as $slot) {
            $macro = 'ndt_' . $slot . '#' . $baris;
            if (!empty($row[$slot . '_path']) && is_file($row[$slot . '_path'])) {
                try {
                    $processor->setImageValue($macro, [
                        'path' => $row[$slot . '_path'],
                        'width' => 220,
                        'height' => 165,
                        'ratio' => true,
                    ]);
                } catch (\Throwable $e) {
                }
            } else {
                try {
                    $processor->setValue($macro, '-');
                } catch (\Throwable $e) {
                }
            }
        }
    }

    foreach ($pjnRows as $i => $row) {
        $baris = $i + 1;
        $setPjn = function (string $field, string $value) use ($processor, $baris) {
            try {
                $processor->setValue($field . '#' . $baris, $value);
            } catch (\Throwable $e) {
            }
        };
        $setPjn('pjn_no', (string) $baris);
        $setPjn('pjn_fungsi', lp_nilai_multiline_ke_xml($row['fungsi']));
        $setPjn('pjn_tinggi_angkat', htmlspecialchars($row['tinggi_angkat'], ENT_QUOTES));
        $setPjn('pjn_kecepatan', htmlspecialchars($row['kecepatan'], ENT_QUOTES));
        $setPjn('pjn_gerakan', lp_nilai_multiline_ke_xml($row['gerakan']));
        $setPjn('pjn_beban', lp_nilai_multiline_ke_xml($row['beban']));
        $setPjn('pjn_hasil', htmlspecialchars($row['hasil'], ENT_QUOTES));
        $setPjn('pjn_ket', lp_nilai_multiline_ke_xml($row['ket']));
    }

    // ----- Nama file -----
    $nomorAwal = explode('/', $nomorLaporan)[0] ?? '000';
    $mentah = $nomorAwal . '. ' . strtoupper($namaTemplate)
        . ($namaPerusahaan !== '' ? ' - ' . strtoupper($namaPerusahaan) : '');
    $namaFile = trim(preg_replace('/[\\\\\/:*?"<>|]+/', '', $mentah));
    $namaFile = preg_replace('/\s+/', ' ', $namaFile) . '.docx';

    if (!is_dir(LAPORAN_PEMERIKSAAN_DIR)) {
        @mkdir(LAPORAN_PEMERIKSAAN_DIR, 0775, true);
    }
    $outputPath = LAPORAN_PEMERIKSAAN_DIR . $namaFile;
    $processor->saveAs($outputPath);
    lp_isi_placeholder_kurung($outputPath, $fields);

    if ($templateSementara && is_file($templateSementara)) {   // <<< ganti dari $templateNdtSementara
        @unlink($templateSementara);
    }

    return 'storage/laporan_pemeriksaan/' . $namaFile;
}