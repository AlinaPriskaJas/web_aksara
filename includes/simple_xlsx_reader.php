<?php
/**
 * includes/simple_xlsx_reader.php
 *
 * Reader .xlsx super ringan TANPA dependency Composer (PhpSpreadsheet dkk).
 * File .xlsx pada dasarnya adalah arsip ZIP berisi XML, jadi cukup pakai
 * ZipArchive + SimpleXML bawaan PHP untuk membacanya.
 *
 * Hanya untuk KEPERLUAN IMPORT (baca saja), bukan penulisan file xlsx.
 *
 * Cara pakai:
 *   $rows = SimpleXlsxReader::readSheet('/path/file.xlsx', 'ATK');
 *   // $rows = array baris, tiap baris = array nilai kolom (string), index 0 = kolom A
 */

class SimpleXlsxReader
{
    /**
     * Baca 1 sheet dari file .xlsx menjadi array baris x kolom (string).
     *
     * @param string $filePath   path file .xlsx di server
     * @param string|int|null $sheet  nama sheet (case-insensitive) ATAU index (0-based).
     *                                 null = sheet pertama.
     * @return array<int, array<int,string>>
     * @throws Exception jika file tidak valid / sheet tidak ditemukan
     */
    public static function readSheet(string $filePath, $sheet = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("File .xlsx tidak dapat dibuka (rusak atau bukan file xlsx yang valid).");
        }

        // 1. Shared strings (semua teks non-angka biasanya disimpan di sini)
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sst = @simplexml_load_string($sharedXml);
            if ($sst !== false) {
                foreach ($sst->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string) $si->t;
                    } else {
                        // rich text (<r><t>...</t></r> berulang)
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                        $sharedStrings[] = $text;
                    }
                }
            }
        }

        // 2. Daftar sheet: nama -> target file XML (via workbook.xml + rels)
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            $zip->close();
            throw new Exception("Struktur file .xlsx tidak lengkap.");
        }

        $wb = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        // map r:id -> target path
        $relMap = [];
        foreach ($rels->Relationship as $rel) {
            $relMap[(string) $rel['Id']] = (string) $rel['Target'];
        }

        $sheetList = []; // index-based list: ['name' => .., 'target' => ..]
        $namespacesWb = $wb->getNamespaces(true);
        $rNs = $namespacesWb['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        foreach ($wb->sheets->sheet as $s) {
            $attrsR = $s->attributes($rNs);
            $rid = (string) $attrsR['id'];
            $target = $relMap[$rid] ?? null;
            if ($target !== null) {
                // target biasanya "worksheets/sheet1.xml"
                if (strpos($target, '/') === 0 || strpos($target, 'xl/') === 0) {
                    $path = ltrim($target, '/');
                } else {
                    $path = 'xl/' . $target;
                }
                $sheetList[] = [
                    'name' => (string) $s['name'],
                    'target' => $path,
                ];
            }
        }

        if (empty($sheetList)) {
            $zip->close();
            throw new Exception("Tidak ada sheet yang ditemukan di file .xlsx.");
        }

        // 3. Tentukan sheet yang dipakai
        $target = null;
        if ($sheet === null) {
            $target = $sheetList[0]['target'];
        } elseif (is_int($sheet)) {
            $target = $sheetList[$sheet]['target'] ?? null;
        } else {
            foreach ($sheetList as $s) {
                if (strcasecmp($s['name'], (string) $sheet) === 0) {
                    $target = $s['target'];
                    break;
                }
            }
            // fallback: kalau nama tidak ketemu persis, pakai sheet pertama
            if ($target === null) {
                $target = $sheetList[0]['target'];
            }
        }

        $sheetXml = $zip->getFromName($target);
        $zip->close();

        if ($sheetXml === false) {
            throw new Exception("Isi sheet tidak dapat dibaca.");
        }

        $sxml = simplexml_load_string($sheetXml);
        $rows = [];

        foreach ($sxml->sheetData->row as $row) {
            $rowIndex = (int) $row['r'] - 1;
            $rowData = [];

            foreach ($row->c as $c) {
                $ref = (string) $c['r'];               // contoh "C5"
                $colLetters = preg_replace('/[0-9]/', '', $ref);
                $colIndex = self::colLettersToIndex($colLetters);

                $type = (string) $c['t'];
                $value = '';

                if (isset($c->v)) {
                    $raw = (string) $c->v;
                    if ($type === 's') {
                        // shared string
                        $value = $sharedStrings[(int) $raw] ?? '';
                    } elseif ($type === 'str' || $type === 'inlineStr') {
                        $value = $raw;
                    } elseif ($type === 'b') {
                        $value = $raw === '1' ? 'TRUE' : 'FALSE';
                    } else {
                        // angka (termasuk serial tanggal Excel)
                        $value = $raw;
                    }
                } elseif (isset($c->is->t)) {
                    // inline string
                    $value = (string) $c->is->t;
                }

                $rowData[$colIndex] = trim($value);
            }

            if (empty($rowData)) {
                continue;
            }
            $maxCol = max(array_keys($rowData));
            $normalized = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $normalized[$i] = $rowData[$i] ?? '';
            }
            $rows[$rowIndex] = $normalized;
        }

        // isi baris kosong yang terlewat & urutkan berdasarkan nomor baris
        ksort($rows);
        return array_values($rows);
    }

    /** Ubah "A", "B", ... "AA" jadi index 0-based */
    private static function colLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }

    /**
     * Konversi serial tanggal Excel (angka) menjadi 'Y-m-d'.
     * Excel menghitung hari sejak 1899-12-30 (termasuk bug tahun kabisat 1900).
     */
    public static function excelDateToYmd(string $value): ?string
    {
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $unixTimestamp = ((float) $value - 25569) * 86400;
        $ts = round($unixTimestamp);
        if ($ts <= 0) {
            return null;
        }
        return gmdate('Y-m-d', (int) $ts);
    }
}
