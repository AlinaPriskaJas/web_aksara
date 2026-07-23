<?php
// includes/topbar.php
require_once __DIR__ . '/avatar_helper.php';

$topbar_nama = ($_SESSION['role'] ?? '') === 'client' && !empty($_SESSION['nama_perusahaan'])
    ? $_SESSION['nama_perusahaan']
    : ($_SESSION['nama_lengkap'] ?? 'Pengguna');

$topbar_foto = $_SESSION['foto_profil'] ?? null;
$topbar_base = $base_url ?? './';
?>

<!-- Main Wrapper for Content and Header -->
<div id="main-wrapper">
<!-- Topbar Header -->
<header id="topbar">
    <!-- Left Section: Hamburger Menu & Title -->
    <div class="topbar-left">
        <button id="hamburger-btn" class="hamburger-btn" aria-label="Toggle Sidebar">
            <i class="bi bi-list"></i>
        </button>
        <div class="topbar-title-section">
            <h1 id="topbar-title" class="topbar-title">Dashboard</h1>
            
        </div>
    </div>

    <!-- Right Section: Search, Status, Notification, Avatar -->
    <div class="topbar-right">
        <!-- Search bar -->
        <div class="topbar-search d-none d-sm-block">
            <div class="search-box-container">
                <i class="bi bi-search"></i>
                <input type="text" class="search-box" placeholder="Cari data...">
            </div>
        </div>

        <!-- Online Status Indicator -->
        <div class="status-indicator">
            <span class="status-dot"></span>
            <span>Online</span>
        </div>

        <!-- Notification Button -->
        <button class="notification-btn" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <span class="notification-badge"></span>
        </button>

        <!-- User Avatar -->
        <a href="profile.php">
            <?= arp_avatar_html($topbar_nama, $topbar_foto, $topbar_base, 40, 'topbar-avatar') ?>
        </a>
    </div>
</header>
