<?php
// includes/pemeriksaan_helper.php
// Dipakai oleh ahli_k3/upload.php, ahli_k3/pemeriksaan.php, dan laporan.php.

/** Data alat yang diisi saat "Tambah Pemeriksaan" (key => label). */
const JP_FIELD_ALAT = [
    'lokasi' => ['label' => 'Lokasi Unit', 'hint' => 'Contoh: Plant 2 / Gudang A'],
    'no_unit' => ['label' => 'No Unit', 'hint' => ''],
    'no_seri' => ['label' => 'No Seri', 'hint' => ''],
    'kapasitas' => ['label' => 'Kapasitas', 'hint' => 'Contoh: 5000 kg / 24 m³ / 705 kW'],
    'merk' => ['label' => 'Merk', 'hint' => ''],
    'tipe' => ['label' => 'Tipe', 'hint' => ''],
    'tahun_alat' => ['label' => 'Tahun Alat', 'hint' => 'Contoh: 2024'],
    'tempat_pembuatan' => ['label' => 'Tempat Pembuatan Alat', 'hint' => 'Contoh: Jepang'],
];

/**
 * Field alat yang DISEMBUNYIKAN per Bidang Objek (id_kategori => [key, ...]).
 * Default: semua tampil. Contoh: 3 => ['tahun_alat'] menyembunyikan Tahun Alat di bidang id 3.
 */
const JP_FIELD_ALAT_SEMBUNYI = [
    // 3 => ['tempat_pembuatan'],
];

/** Placeholder Word yang diisi oleh tiap field alat (dipakai untuk menentukan urutan input). */
const JP_ALAT_PLACEHOLDER = [
    'lokasi' => ['lokasi_unit'],
    'no_unit' => ['no_unit_mesin', 'no_unit_registrasi', 'no_seri_unit_mesin', 'no_seri_unit_kendaraan', 'no_seri_unit'],
    'no_seri' => ['no_seri', 'no_seri_mesin', 'no_seri_unit_mesin', 'no_seri_unit_kendaraan', 'no_seri_unit'],
    'kapasitas' => [
        'kapasitas',
        'kapasitas_daya',
        'kapasitas_keterangan',
        'kapasitas_sesuai_spec',
        'kapasitas_maksimum',
        'kapasitas_spesifikasi',
        'kapasitas_pengujian',
        'kapasitas_volume'
    ],
    'merk' => ['pabrik_pembuat', 'merek_tipe', 'merk_type', 'merk_model', 'dtr_merek_tipe', 'gen_merek_tipe', 'wl_merek_tahun'],
    'tipe' => ['merek_tipe', 'merk_type', 'merk_model', 'dtr_merek_tipe', 'gen_merek_tipe', 'model_type'],
    'tahun_alat' => ['tahun_pembuatan', 'negara_tahun', 'tempat_tahun_pembuatan', 'tempat_tahun_buat', 'lokasi_tahun_pembuatan', 'wl_merek_tahun'],
    'tempat_pembuatan' => ['tempat_pembuatan', 'negara_tahun', 'tempat_tahun_pembuatan', 'tempat_tahun_buat', 'lokasi_tahun_pembuatan'],
];

/** Placeholder yang ditampilkan di form Tambah Pemeriksaan (jika ada di template). */
const JP_FIELD_TAMPIL = [
    'lokasi_unit',
    'merek_tipe',
    'merk_model',
    'merk_type',
    'model_type',
    'no_seri_unit_mesin',
    'no_seri_unit_kendaraan',
    'no_seri',
    'no_seri_mesin',
    'no_unit_mesin',
    'no_seri_unit',
    'kapasitas_daya',
    'kapasitas_keterangan',
    'kapasitas_sesuai_spec',
    'kapasitas_maksimum',
    'kapasitas_spesifikasi',
    'kapasitas_pengujian',
    'kapasitas',
    'kapasitas_volume',
    'pabrik_pembuat',
    'tempat_tahun_pembuatan',
    'lokasi_tahun_pembuatan',
    'tempat_tahun_buat',
    'negara_tahun',
];

/**
 * Field yang tampil di form Tambah Pemeriksaan = placeholder template (bidang+unit)
 * yang ada di JP_FIELD_TAMPIL, urut sesuai Word, tampilan seperti Data Umum.
 * Jika template tidak ada / tidak punya field tsb -> fallback ke field lama.
 */
function jp_field_tampil_untuk(PDO $pdo, int $idKategori, int $idJenis): array
{
    $hasil = [];
    if ($idKategori > 0 && $idJenis > 0) {
        try {
            $st = $pdo->prepare("SELECT * FROM Template_Laporan
                                 WHERE id_kategori = ? AND id_jenis = ? AND drive_file_id IS NOT NULL
                                 ORDER BY nama ASC LIMIT 1");
            $st->execute([$idKategori, $idJenis]);
            $tpl = $st->fetch(PDO::FETCH_ASSOC);
            if ($tpl) {
                $m = lp_muat_fields_template($pdo, $tpl);
                foreach (($m['fields'] ?? []) as $f) {
                    $n = $f['field'];
                    if (!in_array($n, JP_FIELD_TAMPIL, true) || isset($hasil[$n])) {
                        continue;
                    }
                    if (isset(LP_FIELD_PASANGAN[$n])) {
                        $d = LP_FIELD_PASANGAN[$n];
                        $hasil[$n] = [
                            'type' => 'pair',
                            'key' => $n,
                            'label' => $d['label'] ?? $d['kiri'],
                            'row_label' => isset($d['label']),
                            'kiri' => $d['kiri'],
                            'kanan' => $d['kanan'],
                        ];
                    } else {
                        $hasil[$n] = [
                            'type' => 'single',
                            'key' => $n,
                            'label' => $f['label'],
                            'hint' => LP_PLACEHOLDER_FIELD[$n] ?? '',
                            'multiline' => in_array($n, LP_FIELD_MULTILINE, true),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }
    if (!$hasil) {
        foreach (jp_field_alat_untuk($idKategori, []) as $f) {
            $hasil[] = ['type' => 'legacy', 'key' => $f['key'], 'label' => $f['label'], 'hint' => $f['hint']];
        }
    }
    return array_values($hasil);
}

/** Turunkan 8 kolom lama (untuk tabel daftar) dari input field template. */
function jp_turunan_kolom_alat(array $ad, array $ap): array
{
    $pertama = function (array $vals) {
        foreach ($vals as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    };
    $ki = fn($k) => $ap[$k]['kiri'] ?? '';
    $ka = fn($k) => $ap[$k]['kanan'] ?? '';
    $pm = ['merek_tipe', 'merk_type', 'merk_model'];

    return [
        'lokasi' => $pertama([$ad['lokasi_unit'] ?? '']),
        'no_unit' => $pertama([$ad['no_unit_mesin'] ?? '', $ka('no_seri_unit')]),
        'no_seri' => $pertama([
            $ad['no_seri'] ?? '',
            $ad['no_seri_mesin'] ?? '',
            $ad['no_seri_unit_mesin'] ?? '',
            $ad['no_seri_unit_kendaraan'] ?? '',
            $ki('no_seri_unit')
        ]),
        'kapasitas' => $pertama([
            $ad['kapasitas'] ?? '',
            $ad['kapasitas_daya'] ?? '',
            $ad['kapasitas_keterangan'] ?? '',
            $ad['kapasitas_sesuai_spec'] ?? '',
            $ad['kapasitas_maksimum'] ?? '',
            $ad['kapasitas_spesifikasi'] ?? '',
            $ad['kapasitas_pengujian'] ?? '',
            $ad['kapasitas_volume'] ?? ''
        ]),
        'merk' => $pertama(array_merge(array_map($ki, $pm), [$ad['pabrik_pembuat'] ?? ''])),
        'tipe' => $pertama(array_merge(array_map($ka, $pm), [$ki('model_type')])),
        'tahun_alat' => $pertama([$ka('tempat_tahun_pembuatan'), $ka('tempat_tahun_buat'), $ka('lokasi_tahun_pembuatan')]),
        'tempat_pembuatan' => $pertama([
            $ki('tempat_tahun_pembuatan'),
            $ki('tempat_tahun_buat'),
            $ki('lokasi_tahun_pembuatan'),
            $ad['negara_tahun'] ?? ''
        ]),
    ];
}

/** Daftar field alat yang tampil untuk satu bidang. */
/** Daftar field alat yang tampil untuk satu bidang, diurutkan sesuai urutan placeholder di Word. */
function jp_field_alat_untuk(int $idKategori, array $urutanPlaceholder = []): array
{
    $sembunyi = JP_FIELD_ALAT_SEMBUNYI[$idKategori] ?? [];
    $hasil = [];
    $i = 0;
    foreach (JP_FIELD_ALAT as $key => $def) {
        if (in_array($key, $sembunyi, true)) {
            continue;
        }
        // posisi = placeholder paling awal di Word yang diisi field ini
        $pos = PHP_INT_MAX;
        foreach (JP_ALAT_PLACEHOLDER[$key] ?? [] as $ph) {
            $p = array_search($ph, $urutanPlaceholder, true);
            if ($p !== false && $p < $pos) {
                $pos = $p;
            }
        }
        $hasil[] = ['key' => $key, 'label' => $def['label'], 'hint' => $def['hint'], '_pos' => $pos, '_i' => $i++];
    }
    usort($hasil, fn($a, $b) => [$a['_pos'], $a['_i']] <=> [$b['_pos'], $b['_i']]);
    foreach ($hasil as &$h) {
        unset($h['_pos'], $h['_i']);
    }
    return $hasil;
}

/** Urutan nama placeholder (sesuai urutan di file Word) dari template pertama bidang+unit. */
function jp_urutan_placeholder_template(PDO $pdo, int $idKategori, int $idJenis): array
{
    try {
        $st = $pdo->prepare("SELECT * FROM Template_Laporan
                             WHERE id_kategori = ? AND id_jenis = ? AND drive_file_id IS NOT NULL
                             ORDER BY nama ASC LIMIT 1");
        $st->execute([$idKategori, $idJenis]);
        $tpl = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) {
            return [];
        }
        $hasil = lp_muat_fields_template($pdo, $tpl);
        $urut = [];
        foreach (($hasil['fields'] ?? []) as $f) {
            $urut[] = $f['field'];
        }
        return $urut;
    } catch (Throwable $e) {
        return [];
    }
}

/** Tambah kolom baru di Proses_Pemeriksaan secara otomatis (sekali per request). */
function jp_pastikan_kolom(PDO $pdo): void
{
    static $sudah = false;
    if ($sudah) {
        return;
    }
    $sudah = true;
    $kolomBaru = [
        'nama_alat' => 'VARCHAR(150) NULL',
        'lokasi' => 'VARCHAR(255) NULL',
        'nomor_rencana' => 'VARCHAR(100) NULL',
        'no_unit' => 'VARCHAR(100) NULL',
        'no_seri' => 'VARCHAR(100) NULL',
        'kapasitas' => 'VARCHAR(150) NULL',
        'merk' => 'VARCHAR(100) NULL',
        'tipe' => 'VARCHAR(100) NULL',
        'tahun_alat' => 'VARCHAR(10) NULL',
        'tempat_pembuatan' => 'VARCHAR(150) NULL',
        'tanggal_selesai' => 'DATETIME NULL',
        'data_alat_json' => 'TEXT NULL',
    ];
    try {
        $ada = $pdo->query("SHOW COLUMNS FROM Proses_Pemeriksaan")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($kolomBaru as $nama => $tipe) {
            if (!in_array($nama, $ada, true)) {
                $pdo->exec("ALTER TABLE Proses_Pemeriksaan ADD COLUMN `$nama` $tipe");
            }
        }
    } catch (Throwable $e) {
    }
}

/**
 * Peta data alat -> placeholder laporan.
 * Return ['dinamis' => [placeholder => nilai], 'pasangan' => [placeholder => ['kiri'=>..,'kanan'=>..]]]
 * Hanya nilai yang terisi yang dikembalikan; placeholder yang tidak ada di template diabaikan.
 */
function jp_prefill_laporan(array $jp): array
{
    $v = fn(string $k) => trim((string) ($jp[$k] ?? ''));
    $unit = $v('no_unit');
    $seri = $v('no_seri');
    $kap = $v('kapasitas');
    $merk = $v('merk');
    $tipe = $v('tipe');
    $tahun = $v('tahun_alat');
    $tempat = $v('tempat_pembuatan');
    $lok = $v('lokasi');

    $gabung = fn(string $a, string $b) => ($a !== '' && $b !== '') ? $a . '/' . $b : ($a !== '' ? $a : $b);

    $d = [];
    $set = function (array $keys, string $nilai) use (&$d) {
        if ($nilai === '') {
            return;
        }
        foreach ($keys as $k) {
            $d[$k] = $nilai;
        }
    };

    // Kapasitas
    $set([
        'kapasitas',
        'kapasitas_daya',
        'kapasitas_keterangan',
        'kapasitas_sesuai_spec',
        'kapasitas_maksimum',
        'kapasitas_spesifikasi',
        'kapasitas_pengujian',
        'kapasitas_volume'
    ], $kap);
    // No seri / no unit
    $set(['no_seri', 'no_seri_mesin'], $seri);
    $set(['no_unit_mesin', 'no_unit_registrasi'], $unit);
    $set(['no_seri_unit_mesin', 'no_seri_unit_kendaraan'], $gabung($seri, $unit));
    // Pabrik / tempat / tahun (field tunggal)
    $set(['pabrik_pembuat'], $merk);
    $set(['tempat_pembuatan'], $tempat);
    $set(['tahun_pembuatan'], $tahun);
    $set(['negara_tahun'], $gabung($tempat, $tahun));
    $set(['lokasi_unit'], $lok);

    // Field berpasangan (kiri/kanan)
    $p = [];
    $pair = function (array $keys, string $kiri, string $kanan) use (&$p) {
        if ($kiri === '' && $kanan === '') {
            return;
        }
        foreach ($keys as $k) {
            $p[$k] = ['kiri' => $kiri, 'kanan' => $kanan];
        }
    };
    $pair(['merek_tipe', 'merk_type', 'merk_model', 'dtr_merek_tipe', 'gen_merek_tipe'], $merk, $tipe);
    $pair(['model_type'], $tipe, '');
    $pair(['no_seri_unit'], $seri, $unit);
    $pair(['tempat_tahun_pembuatan', 'tempat_tahun_buat', 'lokasi_tahun_pembuatan'], $tempat, $tahun);
    $pair(['wl_merek_tahun'], $merk, $tahun);

    // Nilai yang diinput langsung di Tambah Pemeriksaan (menimpa hasil turunan lama)
    $json = json_decode((string) ($jp['data_alat_json'] ?? ''), true) ?: [];
    foreach (($json['dinamis'] ?? []) as $k => $v) {
        if (trim((string) $v) !== '') {
            $d[$k] = trim((string) $v);
        }
    }
    foreach (($json['pasangan'] ?? []) as $k => $v) {
        $p[$k] = ['kiri' => (string) ($v['kiri'] ?? ''), 'kanan' => (string) ($v['kanan'] ?? '')];
    }

    return ['dinamis' => $d, 'pasangan' => $p];
}

/**
 * Dipanggil SETIAP KALI sebuah Laporan_Pemeriksaan dihapus.
 * Pemeriksaan terkait kembali ke status "belum".
 */
function jp_reset_by_laporan(PDO $pdo, int $laporanId): void
{
    if ($laporanId <= 0) {
        return;
    }
    try {
        $pdo->prepare("UPDATE Proses_Pemeriksaan SET status = 'belum', laporan_id = NULL, tanggal_selesai = NULL WHERE laporan_id = ?")
            ->execute([$laporanId]);
    } catch (Throwable $e) {
    }
}

/** Jaring pengaman + migrasi kolom. */
function jp_self_heal(PDO $pdo): void
{
    jp_pastikan_kolom($pdo);
    try {
        $pdo->exec("UPDATE Proses_Pemeriksaan jp
                    LEFT JOIN Laporan_Pemeriksaan lp ON lp.id = jp.laporan_id
                    SET jp.status = 'belum', jp.laporan_id = NULL, jp.tanggal_selesai = NULL
                    WHERE jp.laporan_id IS NOT NULL AND lp.id IS NULL");
    } catch (Throwable $e) {
    }
}