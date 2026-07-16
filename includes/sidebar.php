<?php
// includes/sidebar.php

$basename = basename($_SERVER['SCRIPT_NAME']);

// Define menus and titles dynamically based on user role directory
$menus = [];
$role_display_name = "User";

switch ($current_role) {
    case 'admin':
        $role_display_name = "Administrator";
        $menus = [
            ['label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'bi-speedometer2'],
            ['label' => 'Approval', 'url' => 'approval.php', 'icon' => 'bi-check-circle'],
            ['label' => 'Database', 'url' => 'database.php', 'icon' => 'bi-database'],
            ['label' => 'Data Klien', 'url' => 'data_klien.php', 'icon' => 'bi-people'],
            ['label' => 'Digital Sign', 'url' => 'digital.php', 'icon' => 'bi-pencil-square'],
            ['label' => 'Print Center', 'url' => 'print.php', 'icon' => 'bi-printer'],
            ['label' => 'Stock Gudang', 'url' => 'stock.php', 'icon' => 'bi-box-seam'],
            ['label' => 'Transportasi', 'url' => 'transportasi.php', 'icon' => 'bi-truck'],
            ['label' => 'Suket K3', 'url' => 'suket.php', 'icon' => 'bi-file-earmark-medical'],
            ['label' => 'Sertifikat Ahli', 'url' => 'sertifikat_ahli.php', 'icon' => 'bi-award'],
            ['label' => 'Jadwal Pemeriksaan', 'url' => 'jadwal.php', 'icon' => 'bi-calendar-event'],
            ['label' => 'Laporan Insiden', 'url' => 'insiden.php', 'icon' => 'bi-exclamation-triangle'],
            ['label' => 'Surat', 'url' => 'surat.php', 'icon' => 'bi-envelope'],
            ['label' => 'Reimburse', 'url' => 'reimburse.php', 'icon' => 'bi-cash-coin'],
            ['label' => 'Absensi', 'url' => 'absensi.php', 'icon' => 'bi-calendar-check'],
            ['label' => 'Cuti', 'url' => 'cuti.php', 'icon' => 'bi-calendar-x'],
            ['label' => 'Profile', 'url' => 'profile.php', 'icon' => 'bi-person']
        ];
        break;

    case 'direksi':
        $role_display_name = "Direktur Utama";
        $menus = [
            ['label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'bi-speedometer2'],
            ['label' => 'Approval Center', 'url' => 'approval.php', 'icon' => 'bi-check-circle'],
            ['label' => 'Monitoring', 'url' => 'monitoring.php', 'icon' => 'bi-tv'],
            ['label' => 'Laporan', 'url' => 'laporan.php', 'icon' => 'bi-file-earmark-bar-graph'],
            ['label' => 'Insiden', 'url' => 'insiden.php', 'icon' => 'bi-exclamation-triangle'],
            ['label' => 'Dokumen Digital', 'url' => 'digital.php', 'icon' => 'bi-file-pdf'],
            ['label' => 'Print Center', 'url' => 'print.php', 'icon' => 'bi-printer'],
            ['label' => 'Surat', 'url' => 'surat.php', 'icon' => 'bi-envelope'],
            ['label' => 'Reimburse', 'url' => 'reimburse.php', 'icon' => 'bi-cash-coin'],
            ['label' => 'Absensi', 'url' => 'absensi.php', 'icon' => 'bi-calendar-check'],
            ['label' => 'Cuti', 'url' => 'cuti.php', 'icon' => 'bi-calendar-x'],
            ['label' => 'Profile', 'url' => 'profile.php', 'icon' => 'bi-person']
        ];
        break;

    case 'it':
        $role_display_name = "IT Administrator";
        $menus = [
            ['label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'bi-speedometer2'],
            ['label' => 'Database IT', 'url' => 'database.php', 'icon' => 'bi-database-fill-gear'],
            ['label' => 'Digital Assets', 'url' => 'digital.php', 'icon' => 'bi-hdd-network'],
            ['label' => 'User Management', 'url' => 'user.php', 'icon' => 'bi-people'],
            ['label' => 'Keamanan', 'url' => 'keamanan.php', 'icon' => 'bi-shield-lock'],
            ['label' => 'Backup Data', 'url' => 'backup.php', 'icon' => 'bi-cloud-arrow-up'],
            ['label' => 'System Audit', 'url' => 'audit.php', 'icon' => 'bi-shield-check'],
            ['label' => 'Surat', 'url' => 'surat.php', 'icon' => 'bi-envelope'],
            ['label' => 'Reimburse', 'url' => 'reimburse.php', 'icon' => 'bi-cash-coin'],
            ['label' => 'Absensi', 'url' => 'absensi.php', 'icon' => 'bi-calendar-check'],
            ['label' => 'Cuti', 'url' => 'cuti.php', 'icon' => 'bi-calendar-x'],
            ['label' => 'Pengaturan', 'url' => 'pengaturan.php', 'icon' => 'bi-gear'],
            ['label' => 'Profile', 'url' => 'profile.php', 'icon' => 'bi-person']
        ];
        break;

    case 'ahlik3':
        $role_display_name = "Ahli K3 Pratama";
        $menus = [
            ['label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'bi-speedometer2'],
            ['label' => 'Jadwal Pemeriksaan', 'url' => 'jadwal.php', 'icon' => 'bi-calendar-event'],
            ['label' => 'Upload Laporan', 'url' => 'upload.php', 'icon' => 'bi-upload'],
            ['label' => 'Input Hasil', 'url' => 'input_hasil.php', 'icon' => 'bi-file-earmark-medical'],
            ['label' => 'Rekomendasi', 'url' => 'rekomendasi.php', 'icon' => 'bi-exclamation-triangle'],
            ['label' => 'Laporan Insiden', 'url' => 'insiden.php', 'icon' => 'bi-cone-striped'],
            ['label' => 'Sertifikat Ahli K3', 'url' => 'sertifikat_ahli.php', 'icon' => 'bi-award'],
            ['label' => 'Riwayat K3', 'url' => 'riwayat.php', 'icon' => 'bi-journal-text'],
            ['label' => 'Surat', 'url' => 'surat.php', 'icon' => 'bi-envelope'],
            ['label' => 'Reimburse', 'url' => 'reimburse.php', 'icon' => 'bi-cash-coin'],
            ['label' => 'Absensi', 'url' => 'absensi.php', 'icon' => 'bi-calendar-check'],
            ['label' => 'Cuti', 'url' => 'cuti.php', 'icon' => 'bi-calendar-x'],
            ['label' => 'Profile', 'url' => 'profile.php', 'icon' => 'bi-person']
        ];
        break;

    case 'client':
        $role_display_name = "Corporate Client";
        $menus = [
            ['label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'bi-speedometer2'],
            ['label' => 'Pengajuan Pemeriksaan', 'url' => 'pengajuan.php', 'icon' => 'bi-file-earmark-plus'],
            ['label' => 'Status Pemeriksaan', 'url' => 'status.php', 'icon' => 'bi-hourglass-split'],
            ['label' => 'Riwayat Pemeriksaan', 'url' => 'riwayat.php', 'icon' => 'bi-clock-history'],
            ['label' => 'Suket K3', 'url' => 'suket.php', 'icon' => 'bi-file-earmark-text'],
            ['label' => 'Profile', 'url' => 'profile.php', 'icon' => 'bi-person']
        ];
        break;

    default:
        $role_display_name = "Guest User";
        $menus = [
            ['label' => 'Dashboard', 'url' => $base_url . 'index.php', 'icon' => 'bi-house-door']
        ];
        break;
}

// User Info Mockups
$user_name = "Budi Hartono";
if ($current_role == 'admin')
    $user_name = "Aditya Pratama";
if ($current_role == 'direksi')
    $user_name = "Rian Aksara";
if ($current_role == 'it')
    $user_name = "Daffa IT Tech";
if ($current_role == 'ahlik3')
    $user_name = "Hendra K3";
if ($current_role == 'client')
    $user_name = "PT Mitra Sejahtera";
?>
<!-- Sidebar -->
<aside id="sidebar">
    <!-- Brand Header -->
    <a href="<?php echo $base_url; ?>index.php" class="sidebar-brand">
        <div class="d-flex align-items-center justify-content-center me-2 bg-warning rounded text-success"
            style="width: 36px; height: 36px; flex-shrink: 0;">
            <i class="bi bi-shield fs-4"></i>
        </div>
        <span class="sidebar-brand-text">PT Aksara Riksa<br><small
                style="font-size:0.75rem; font-weight: normal; color: var(--sidebar-color);">Perdana</small></span>
    </a>

    <!-- Navigation Menu -->
    <ul class="sidebar-menu">
        <?php foreach ($menus as $menu):
            $isActive = ($basename === $menu['url']);
            ?>
            <li class="<?php echo $isActive ? 'active' : ''; ?>">
                <a href="<?php echo $menu['url']; ?>">
                    <i class="bi <?php echo $menu['icon']; ?>"></i>
                    <span><?php echo $menu['label']; ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Sidebar User Footer -->
    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <img src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=256&auto=format&fit=crop"
                alt="User Avatar" class="sidebar-user-avatar">
            <div class="sidebar-user-detail">
                <div class="sidebar-user-name" title="<?php echo htmlspecialchars($user_name); ?>">
                    <?php echo htmlspecialchars($user_name); ?>
                </div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($role_display_name); ?></div>
            </div>
        </div>
        <a href="<?php echo $base_url; ?>index.php?logout=true" class="sidebar-logout-btn">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>