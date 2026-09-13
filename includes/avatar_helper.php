<?php
/**
 * includes/avatar_helper.php
 *
 * Avatar ala WhatsApp:
 * - Kalau user belum pernah upload foto profil -> tampil lingkaran warna
 *   berisi inisial nama (konsisten warnanya untuk nama yang sama).
 * - Kalau user sudah upload foto -> tampil foto asli.
 *
 * Dipakai di: includes/topbar.php dan semua halaman profile.php per-role.
 */

if (!function_exists('arp_get_initials')) {
    function arp_get_initials($nama) {
        $nama = trim((string) $nama);
        if ($nama === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $nama);
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }
        $depan     = mb_substr($parts[0], 0, 1);
        $belakang  = mb_substr($parts[count($parts) - 1], 0, 1);
        return mb_strtoupper($depan . $belakang);
    }
}

if (!function_exists('arp_avatar_color')) {
    function arp_avatar_color($nama) {
        // Palet warna senada dengan tema aplikasi (mirip gaya avatar WhatsApp)
        $palette = [
            '#1b63c4', '#2ecc71', '#e67e22', '#9b59b6',
            '#e74c3c', '#16a085', '#d35400', '#2980b9',
            '#8e44ad', '#c0392b', '#27ae60', '#f39c12',
        ];
        $index = abs(crc32((string) $nama)) % count($palette);
        return $palette[$index];
    }
}

if (!function_exists('arp_foto_profil_exists')) {
    function arp_foto_profil_exists($foto_path) {
        if (empty($foto_path)) {
            return false;
        }
        $disk_path = realpath(__DIR__ . '/../') . '/' . ltrim($foto_path, '/');
        return is_file($disk_path);
    }
}

if (!function_exists('arp_avatar_html')) {
    /**
     * @param string      $nama     Nama lengkap user
     * @param string|null $foto     Path relatif (dari root project) foto profil, mis. "uploads/profil/3_167xxx.jpg"
     * @param string      $base_url "./" atau "../" sesuai kedalaman halaman saat ini
     * @param int         $size     Diameter avatar dalam px
     * @param string      $class    Class CSS tambahan
     */
    function arp_avatar_html($nama, $foto, $base_url = './', $size = 40, $class = '') {
        if (arp_foto_profil_exists($foto)) {
            $disk_path = realpath(__DIR__ . '/../') . '/' . ltrim($foto, '/');
            $src = $base_url . ltrim($foto, '/') . '?v=' . filemtime($disk_path);
            return '<img src="' . htmlspecialchars($src) . '" alt="Foto Profil" '
                 . 'class="avatar-img ' . htmlspecialchars($class) . '" '
                 . 'style="width:' . (int) $size . 'px;height:' . (int) $size . 'px;">';
        }

        $initials  = arp_get_initials($nama);
        $color     = arp_avatar_color($nama ?: 'ARP');
        $font_size = max(12, round($size * 0.42));

        return '<div class="avatar-initial ' . htmlspecialchars($class) . '" '
             . 'style="width:' . (int) $size . 'px;height:' . (int) $size . 'px;'
             . 'background:' . $color . ';font-size:' . $font_size . 'px;">'
             . htmlspecialchars($initials) . '</div>';
    }
}