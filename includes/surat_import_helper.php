<?php
// includes/surat_import_helper.php
//
// Helper untuk fitur "Import Surat" (upload banyak file surat lama sekaligus
// dari laptop, lalu sistem MENEBAK otomatis Nomor/Perihal/Yth/Tanggal dari
// ISI file-nya) yang dipakai admin/surat.php (tab Surat Keluar & Surat Masuk).
//
// Prinsip desain:
// - TIDAK menambah dependency composer baru (mis. smalot/pdfparser), supaya
//   tidak perlu `composer install` ulang di server produksi. Ekstraksi teks
//   docx pakai ZipArchive bawaan PHP (persis pola yang sudah dipakai
//   extractPerihalFromDocxText() di functions.php). Ekstraksi teks PDF pakai
//   parser ringan buatan sendiri (baca stream FlateDecode + operator Tj/TJ).
// - Hanya untuk PDF "teks asli" (hasil export Word/aplikasi kantor, BUKAN
//   hasil scan/foto). PDF hasil scan tidak punya lapisan teks sama sekali
//   sehingga tidak akan bisa dibaca otomatis -- ini sesuai kebutuhan
//   (PDF teks asli), bukan keterbatasan yang perlu OCR.
// - Deteksi otomatis bersifat "best effort": hasilnya SELALU bisa diedit
//   manual oleh admin di layar preview sebelum benar-benar disimpan, jadi
//   kalaupun deteksi meleset, tidak ada data yang salah masuk ke database
//   tanpa sepengetahuan admin.

if (!function_exists('arp_import_ekstrak_teks')) {

    /**
     * Titik masuk utama: baca file .docx atau .pdf, kembalikan teks polos + status.
     *
     * @return array{ok: bool, teks: string, error: string, confidence: string}
     */
    function arp_import_ekstrak_teks(string $path, string $ekstensi): array
    {
        $ekstensi = strtolower($ekstensi);
        try {
            if ($ekstensi === 'docx') {
                $teks = arp_import_baca_docx($path);
                if ($teks === null) {
                    return ['ok' => false, 'teks' => '', 'error' => 'Gagal membuka isi file .docx (mungkin rusak atau bukan docx asli).', 'confidence' => 'rendah'];
                }
                return ['ok' => true, 'teks' => $teks, 'error' => '', 'confidence' => 'tinggi'];
            }

            if ($ekstensi === 'pdf') {
                $hasil = arp_import_baca_pdf($path);
                if ($hasil['teks'] === '') {
                    return ['ok' => false, 'teks' => '', 'error' => 'Tidak ditemukan teks di dalam PDF ini (kemungkinan hasil scan/foto, bukan PDF teks asli).', 'confidence' => 'rendah'];
                }
                return ['ok' => true, 'teks' => $hasil['teks'], 'error' => '', 'confidence' => $hasil['confidence']];
            }

            return ['ok' => false, 'teks' => '', 'error' => 'Format file tidak didukung.', 'confidence' => 'rendah'];
        } catch (Throwable $e) {
            return ['ok' => false, 'teks' => '', 'error' => 'Gagal membaca file: ' . $e->getMessage(), 'confidence' => 'rendah'];
        }
    }

    /**
     * Baca teks polos dari .docx (word/document.xml di dalam ZIP-nya).
     * Pola sama dengan extractPerihalFromDocxText() di functions.php, dibuat
     * lebih umum supaya mengembalikan SELURUH teks (bukan cuma baris Perihal).
     */
    function arp_import_baca_docx(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return null;
        }

        // Ganti penanda struktur docx dengan newline/tab supaya hasil strip_tags
        // masih berbentuk baris-baris yang bisa dibaca per baris (mis. "Nomor : ..."
        // tidak nempel dengan baris "Perihal : ...").
        $xml = str_replace(
            ['</w:p>', '</w:tr>', '<w:br/>', '<w:br />', '<w:tab/>', '<w:tab />'],
            ["</w:p>\n", "</w:tr>\n", "\n", "\n", "\t", "\t"],
            $xml
        );
        $plain = preg_replace('/<[^>]+>/', '', $xml);
        $plain = html_entity_decode((string) $plain, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return $plain;
    }

    /**
     * Parser PDF ringan tanpa dependency luar. Hanya menyasar PDF dengan
     * lapisan teks asli (bukan hasil scan). Cara kerja:
     *   1. Cari semua blok `stream ... endstream` yang memakai filter FlateDecode,
     *      lalu di-inflate (gzuncompress) -- ini isi "content stream" halaman PDF.
     *   2. Dari isi content stream, ambil teks yang ditampilkan lewat operator
     *      Tj / TJ (dan variasi ' / "), abaikan operator grafis lain.
     *   3. Operator pindah baris (Td/TD/T*) dianggap sebagai baris baru, supaya
     *      hasilnya cukup mirip tata letak aslinya untuk keperluan pencarian
     *      kata kunci (Nomor/Perihal/Yth/Tanggal).
     *
     * @return array{teks: string, confidence: string}
     */
    function arp_import_baca_pdf(string $path): array
    {
        $isiFile = @file_get_contents($path);
        if ($isiFile === false || $isiFile === '') {
            return ['teks' => '', 'confidence' => 'rendah'];
        }

        $potonganTeks = [];

        // Tangkap setiap objek PDF utuh supaya kita tahu dictionary (dan filter)
        // yang berlaku untuk stream di dalamnya.
        if (preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $isiFile, $cocokan, PREG_SET_ORDER)) {
            foreach ($cocokan as $obj) {
                $dict = $obj[1];
                $streamMentah = $obj[2];

                // Lewati stream gambar (XObject/Image) -- bukan teks, dan bisa
                // sangat besar sehingga membuang waktu inflate untuk apa-apa.
                if (stripos($dict, '/Image') !== false) {
                    continue;
                }

                $konten = $streamMentah;
                if (stripos($dict, 'FlateDecode') !== false) {
                    $hasilInflate = @gzuncompress($streamMentah);
                    if ($hasilInflate === false) {
                        // Sebagian generator PDF menyisipkan byte ekstra di awal/akhir;
                        // coba sekali lagi pakai zlib inflate mentah sebagai fallback.
                        $hasilInflate = @gzinflate(substr($streamMentah, 2));
                    }
                    if ($hasilInflate === false) {
                        continue; // gagal decompress, lewati blok ini
                    }
                    $konten = $hasilInflate;
                }

                // Heuristik sederhana: content stream halaman biasanya mengandung
                // operator teks "Tj"/"TJ"/"BT". Kalau tidak ada sama sekali,
                // kemungkinan ini bukan content stream (mis. font program /
                // ToUnicode CMap terkompresi), jadi dilewati.
                if (strpos($konten, 'Tj') === false && strpos($konten, 'TJ') === false) {
                    continue;
                }

                $potonganTeks[] = arp_import_pdf_ambil_teks_dari_stream($konten);
            }
        }

        $teksGabungan = trim(implode("\n", array_filter($potonganTeks, static fn($t) => trim($t) !== '')));
        $teksGabungan = preg_replace('/\n{3,}/', "\n\n", $teksGabungan);

        if ($teksGabungan === '') {
            return ['teks' => '', 'confidence' => 'rendah'];
        }

        // Ukur rasio karakter "wajar" (huruf/angka/spasi/tanda baca umum Latin)
        // supaya kalau hasilnya kebanyakan karakter aneh (indikasi font CID /
        // Identity-H yang tidak bisa dipetakan tanpa CMap), kita beri tahu
        // admin supaya field-nya dicek manual, bukan dipercaya buta.
        $totalKarakter = mb_strlen($teksGabungan);
        preg_match_all('/[\x20-\x7E\n]/', $teksGabungan, $karakterWajar);
        $rasioWajar = $totalKarakter > 0 ? (count($karakterWajar[0]) / $totalKarakter) : 0;

        return [
            'teks' => $teksGabungan,
            'confidence' => $rasioWajar >= 0.75 ? 'tinggi' : 'rendah',
        ];
    }

    /**
     * Ambil semua teks yang ditampilkan (operator Tj / TJ / ' / ") dari satu
     * content stream PDF yang sudah di-decompress, sambil menyisipkan newline
     * setiap kali ada operator pindah baris (Td/TD/T*).
     */
    function arp_import_pdf_ambil_teks_dari_stream(string $konten): string
    {
        $hasil = '';
        $panjang = strlen($konten);
        $i = 0;

        while ($i < $panjang) {
            $karakter = $konten[$i];

            // --- String literal: (...) diikuti operator Tj / ' / " ---
            if ($karakter === '(') {
                [$teksLiteral, $posSetelah] = arp_import_pdf_baca_string_literal($konten, $i);
                $i = $posSetelah;
                // Lihat operator setelah string ini (lewati spasi/angka kerning dulu
                // untuk kasus array TJ yang formatnya "[(a) -200 (b)] TJ").
                $hasil .= arp_import_pdf_decode_bytes($teksLiteral);
                continue;
            }

            // --- Array untuk operator TJ: [ (..) angka (..) ... ] TJ ---
            if ($karakter === '[') {
                $j = $i + 1;
                $isiArray = '';
                while ($j < $panjang && $konten[$j] !== ']') {
                    if ($konten[$j] === '(') {
                        [$teksLiteral, $posSetelah] = arp_import_pdf_baca_string_literal($konten, $j);
                        $isiArray .= arp_import_pdf_decode_bytes($teksLiteral);
                        $j = $posSetelah;
                        continue;
                    }
                    $j++;
                }
                $hasil .= $isiArray;
                $i = $j + 1;
                continue;
            }

            // --- Operator pindah baris: Td, TD, T*, ', " -> anggap baris baru ---
            if (
                preg_match('/\GT[dD]\b/', $konten, $m, 0, $i)
                || preg_match('/\GT\*/', $konten, $m, 0, $i)
                || preg_match('/\G[\'"]/', $konten, $m, 0, $i)
            ) {
                $hasil .= "\n";
                $i += strlen($m[0]);
                continue;
            }

            $i++;
        }

        return $hasil;
    }

    /**
     * Baca satu string literal PDF "(...)" mulai dari posisi tanda kurung buka,
     * menangani escape \( \) \\ dan nested parentheses yang tidak di-escape.
     * Mengembalikan [teks_mentah_didalam_kurung, posisi_setelah_kurung_tutup].
     */
    function arp_import_pdf_baca_string_literal(string $konten, int $posisiKurungBuka): array
    {
        $panjang = strlen($konten);
        $i = $posisiKurungBuka + 1;
        $level = 1;
        $teks = '';

        while ($i < $panjang && $level > 0) {
            $c = $konten[$i];
            if ($c === '\\' && $i + 1 < $panjang) {
                $next = $konten[$i + 1];
                switch ($next) {
                    case 'n':
                        $teks .= "\n";
                        break;
                    case 'r':
                        $teks .= "\r";
                        break;
                    case 't':
                        $teks .= "\t";
                        break;
                    case '(':
                    case ')':
                    case '\\':
                        $teks .= $next;
                        break;
                    default:
                        if (ctype_digit($next)) {
                            // Escape oktal \ddd (1-3 digit)
                            $oktal = $next;
                            $k = $i + 2;
                            for ($n = 0; $n < 2 && $k < $panjang && ctype_digit($konten[$k]); $n++, $k++) {
                                $oktal .= $konten[$k];
                            }
                            $teks .= chr(octdec($oktal) & 0xFF);
                            $i = $k - 2;
                        } else {
                            $teks .= $next;
                        }
                }
                $i += 2;
                continue;
            }
            if ($c === '(') {
                $level++;
                $teks .= $c;
                $i++;
                continue;
            }
            if ($c === ')') {
                $level--;
                $i++;
                if ($level > 0) {
                    $teks .= $c;
                }
                continue;
            }
            $teks .= $c;
            $i++;
        }

        return [$teks, $i];
    }

    /**
     * Konversi byte mentah hasil string literal PDF menjadi teks UTF-8.
     * Font sederhana (WinAnsiEncoding/single-byte) hampir sama dengan
     * Windows-1252, jadi dipakai sebagai asumsi default -- cukup akurat
     * untuk dokumen perkantoran berbahasa Indonesia yang mayoritas ASCII.
     */
    function arp_import_pdf_decode_bytes(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        $hasil = @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        return $hasil !== false ? $hasil : $bytes;
    }
}

if (!function_exists('arp_import_parse_semua')) {

    /** Pecah teks jadi baris-baris bersih (trim, buang baris kosong berlebih). */
    function arp_import_baris_teks(string $teks): array
    {
        $baris = preg_split('/\r\n|\r|\n/', $teks);
        return array_values(array_filter(array_map('trim', $baris), static fn($b) => $b !== ''));
    }

    /**
     * Jalankan semua parser field sekaligus atas satu teks surat.
     * @return array{nomor: ?string, perihal: ?string, tujuan: ?string, tanggal: ?string}
     */
    function arp_import_parse_semua(string $teks): array
    {
        $baris = arp_import_baris_teks($teks);
        return [
            'nomor' => arp_import_parse_nomor($baris),
            'perihal' => arp_import_parse_perihal($baris),
            'tujuan' => arp_import_parse_tujuan($baris),
            'tanggal' => arp_import_parse_tanggal($teks),
        ];
    }

    /** Cari baris "Nomor : ..." / "No. : ..." / "No 002/ABC/2026" (dengan/tanpa separator),
     *  termasuk kasus label & isi terpisah baris ("No" saja lalu nomornya di baris berikutnya). */
    function arp_import_parse_nomor(array $baris): ?string
    {
        foreach ($baris as $idx => $b) {
            // \b setelah "no" mencegah salah tangkap kata seperti "November".
            if (preg_match('/^no(?:mor)?\b\.?\s*(?:surat)?\s*[:\-]?\s*(.*)$/i', $b, $m)) {
                $nilai = trim($m[1], " \t.:-");
                if ($nilai !== '') {
                    return $nilai;
                }
                // Baris ini cuma label ("No" / "Nomor :") tanpa isi -> cek baris berikutnya.
                $berikutnya = $baris[$idx + 1] ?? null;
                if ($berikutnya !== null && !preg_match('/^(perihal|hal|yth|kepada|lampiran|lamp)\b/i', $berikutnya)) {
                    $nilaiBerikutnya = trim($berikutnya, " \t.:-");
                    if ($nilaiBerikutnya !== '') {
                        return $nilaiBerikutnya;
                    }
                }
            }
        }
        return null;
    }

    /** Cari baris "Perihal : ..." / "Hal : ...". */
    function arp_import_parse_perihal(array $baris): ?string
    {
        foreach ($baris as $b) {
            if (preg_match('/^(?:perihal|hal)\s*[:\-]\s*(.+)$/i', $b, $m)) {
                $nilai = trim($m[1], " \t.:-");
                if ($nilai !== '') {
                    return $nilai;
                }
            }
        }
        return null;
    }

    /** Bersihkan embel-embel "Di Tempat" yang kadang menempel di baris nama/tujuan. */
    function arp_import_bersihkan_tujuan(string $teks): string
    {
        $teks = preg_replace('/[\s,]*\bdi\s+tempat\b\.?\s*$/i', '', $teks);
        return trim((string) $teks, " \t.,:-");
    }

    /** Cek apakah satu baris HANYA berisi "Di" / "Tempat" / "Di Tempat" (dalam berbagai variasi). */
    function arp_import_baris_adalah_di_tempat(string $baris): bool
    {
        $bersih = trim($baris, " \t.,:-");
        return $bersih !== '' && (
            preg_match('/^di\s*tempat$/i', $bersih) === 1
            || preg_match('/^di$/i', $bersih) === 1
            || preg_match('/^tempat$/i', $bersih) === 1
        );
    }

        /** Ambil sampai 3 baris setelah $idx sebagai nama tujuan, berhenti kalau ketemu
     *  "Di"/"Tempat", label field lain, atau "Pihak Kedua" (biar tidak ikut tertarik). */
    function arp_import_ambil_baris_tujuan_setelah(array $baris, int $idx): ?string
    {
        $kumpulan = [];
        for ($n = 1; $n <= 3; $n++) {
            $berikutnya = $baris[$idx + $n] ?? null;
            if ($berikutnya === null) {
                break;
            }
            if (arp_import_baris_adalah_di_tempat($berikutnya)) {
                break;
            }
            if (preg_match('/^(no(?:mor)?|perihal|hal|dengan\s+hormat|pihak\s+kedua)\b/i', $berikutnya)) {
                break;
            }
            $kumpulan[] = arp_import_bersihkan_tujuan($berikutnya);
        }
        $gabungan = trim(implode(', ', array_filter($kumpulan, fn($x) => $x !== '')));
        return $gabungan !== '' ? $gabungan : null;
    }

    function arp_import_parse_tujuan(array $baris): ?string
    {
        foreach ($baris as $idx => $b) {
            // Pola 1: "Yth. ..." / "Kepada Yth. ..."
            if (preg_match('/^(?:kepada\s*[:.,]?\s*)?yth\.?\s*[:.,]?\s*(.*)$/i', $b, $m)) {
                $langsung = arp_import_bersihkan_tujuan(trim($m[1], " \t.:-,"));
                if ($langsung !== '' && !arp_import_baris_adalah_di_tempat($langsung)) {
                    return $langsung;
                }
                $hasil = arp_import_ambil_baris_tujuan_setelah($baris, $idx);
                if ($hasil !== null) {
                    return $hasil;
                }
            }

            // Pola 2: "Kepada Pimpinan :" (tanpa kata "Yth") -> nama perusahaan di baris berikutnya.
            if (preg_match('/^kepada\s+pimpinan\s*[:.,]?\s*(.*)$/i', $b, $m)) {
                $langsung = arp_import_bersihkan_tujuan(trim($m[1], " \t.:-,"));
                if ($langsung !== '' && !arp_import_baris_adalah_di_tempat($langsung)) {
                    return $langsung;
                }
                $hasil = arp_import_ambil_baris_tujuan_setelah($baris, $idx);
                if ($hasil !== null) {
                    return $hasil;
                }
            }

            // Pola 3: "Pihak Pertama" -> nama perusahaan/pihak di baris berikutnya.
            if (preg_match('/^pihak\s+pertama\s*[:.,]?\s*(.*)$/i', $b, $m)) {
                $langsung = arp_import_bersihkan_tujuan(trim($m[1], " \t.:-,"));
                if ($langsung !== '' && !arp_import_baris_adalah_di_tempat($langsung)) {
                    return $langsung;
                }
                $hasil = arp_import_ambil_baris_tujuan_setelah($baris, $idx);
                if ($hasil !== null) {
                    return $hasil;
                }
            }
        }
        return null;
    }

    /**
     * Cari tanggal surat. Prioritas: format tanggal Indonesia "5 September 2026"
     * (biasa muncul di kop surat, mis. "Bandung, 5 September 2026"). Kalau
     * tidak ketemu, coba format numerik dd/mm/yyyy atau dd-mm-yyyy.
     * Hasil dikembalikan dalam format Y-m-d, atau null kalau tidak ketemu.
     */
    /**
     * Cari tanggal surat. Mendukung format kop surat "Kota, 27 Januari 2026"
     * maupun "Kota, 27 January 2026" (nama bulan Indonesia ATAU Inggris,
     * termasuk singkatannya seperti "Jan", "Feb", dst).
     * Kalau tidak ketemu, coba format numerik dd/mm/yyyy atau dd-mm-yyyy.
     * Hasil dikembalikan dalam format Y-m-d, atau null kalau tidak ketemu.
     */
    function arp_import_parse_tanggal(string $teks): ?string
    {
        static $bulanMap = [
        // ----- Bahasa Indonesia -----
        'januari' => 1,
        'februari' => 2,
        'maret' => 3,
        'april' => 4,
        'mei' => 5,
        'juni' => 6,
        'juli' => 7,
        'agustus' => 8,
        'september' => 9,
        'oktober' => 10,
        'november' => 11,
        'desember' => 12,
        // ----- Bahasa Inggris (nama lengkap & singkatan umum) -----
        'january' => 1,
        'jan' => 1,
        'february' => 2,
        'feb' => 2,
        'march' => 3,
        'mar' => 3,
        'april' => 4,
        'apr' => 4,
        'may' => 5,
        'june' => 6,
        'jun' => 6,
        'july' => 7,
        'jul' => 7,
        'august' => 8,
        'aug' => 8,
        'october' => 10,
        'oct' => 10,
        'november' => 11,
        'nov' => 11,
        'december' => 12,
        'dec' => 12,
        // 'september'/'sep' bertabrakan nama dgn versi ID tapi angkanya sama, aman.
        'sep' => 9,
        'sept' => 9,
        ];

        // Urutkan key terpanjang dulu supaya "september" tidak "kepotong" jadi "sep"
        // saat dipakai di alternation regex (mis. "sep" match duluan padahal harusnya "september").
        $namaBulan = array_keys($bulanMap);
        usort($namaBulan, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $polaBulan = implode('|', array_map('preg_quote', $namaBulan));

        // Format "27 Januari 2026" / "27 January 2026" / "27 Jan 2026"
        // (boleh didahului nama kota & koma, tidak wajib -- \b sudah cukup).
        if (preg_match('/\b(\d{1,2})\s+(' . $polaBulan . ')\.?\s*,?\s+(\d{4})\b/i', $teks, $m)) {
            $tanggal = (int) $m[1];
            $bulan = $bulanMap[strtolower($m[2])];
            $tahun = (int) $m[3];
            if (checkdate($bulan, $tanggal, $tahun)) {
                return sprintf('%04d-%02d-%02d', $tahun, $bulan, $tanggal);
            }
        }

        // Format Amerika "January 27, 2026"
        if (preg_match('/\b(' . $polaBulan . ')\.?\s+(\d{1,2}),?\s+(\d{4})\b/i', $teks, $m)) {
            $bulan = $bulanMap[strtolower($m[1])];
            $tanggal = (int) $m[2];
            $tahun = (int) $m[3];
            if (checkdate($bulan, $tanggal, $tahun)) {
                return sprintf('%04d-%02d-%02d', $tahun, $bulan, $tanggal);
            }
        }

        // Format numerik dd/mm/yyyy atau dd-mm-yyyy
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/', $teks, $m)) {
            $tanggal = (int) $m[1];
            $bulan = (int) $m[2];
            $tahun = (int) $m[3];
            if (checkdate($bulan, $tanggal, $tahun)) {
                return sprintf('%04d-%02d-%02d', $tahun, $bulan, $tanggal);
            }
        }

        // Format yyyy-mm-dd
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $teks, $m)) {
            $tahun = (int) $m[1];
            $bulan = (int) $m[2];
            $tanggal = (int) $m[3];
            if (checkdate($bulan, $tanggal, $tahun)) {
                return sprintf('%04d-%02d-%02d', $tahun, $bulan, $tanggal);
            }
        }

        return null;
    }
}

if (!function_exists('arp_import_hapus_folder')) {
    /** Hapus folder sementara batch import beserta seluruh isinya. */
    function arp_import_hapus_folder(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $item = scandir($dir);
        if ($item === false) {
            return;
        }
        foreach ($item as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            if (is_dir($path)) {
                arp_import_hapus_folder($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Bersihkan folder batch import yang sudah lama tidak dipakai (mis. admin
     * upload lalu menutup browser tanpa Simpan/Batal), supaya tidak menumpuk
     * di server selamanya. Dipanggil setiap kali ada upload import baru.
     */
    function arp_import_bersihkan_batch_lama(string $dirIndukTmpImport, int $batasJamKadaluarsa = 12): void
    {
        if (!is_dir($dirIndukTmpImport)) {
            return;
        }
        $batasWaktu = time() - ($batasJamKadaluarsa * 3600);
        foreach (scandir($dirIndukTmpImport) ?: [] as $sub) {
            if ($sub === '.' || $sub === '..') {
                continue;
            }
            $path = $dirIndukTmpImport . '/' . $sub;
            if (is_dir($path) && filemtime($path) < $batasWaktu) {
                arp_import_hapus_folder($path);
            }
        }
    }
}
