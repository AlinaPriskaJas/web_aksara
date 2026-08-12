<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Dynamic Base URL calculation to support deep folder pages (e.g. admin/dashboard.php)
$current_script = $_SERVER['SCRIPT_NAME'];
$is_sub = (
    strpos($current_script, '/admin/') !== false ||
    strpos($current_script, '/direksi/') !== false ||
    strpos($current_script, '/it/') !== false ||
    strpos($current_script, '/ahlik3/') !== false ||
    strpos($current_script, '/client/') !== false
);
$base_url = $is_sub ? '../' : './';

// Get current role directory name
$current_role = 'dashboard';
if (strpos($current_script, '/admin/') !== false) {
    $current_role = 'admin';
} elseif (strpos($current_script, '/direksi/') !== false) {
    $current_role = 'direksi';
} elseif (strpos($current_script, '/it/') !== false) {
    $current_role = 'it';
} elseif (strpos($current_script, '/ahlik3/') !== false) {
    $current_role = 'ahlik3';
} elseif (strpos($current_script, '/client/') !== false) {
    $current_role = 'client';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : "PT Aksara Riksa Perdana"; ?></title>
    
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons CDN (Icon System) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Design System Core CSS -->
    <link rel="stylesheet" href="<?php echo $base_url; ?>assets/css/variables.css">
    <link rel="stylesheet" href="<?php echo $base_url; ?>assets/css/style.css">
    <link rel="stylesheet" href="<?php echo $base_url; ?>assets/css/components.css">
    <link rel="stylesheet" href="<?php echo $base_url; ?>assets/css/responsive.css">
    
    <!-- Role specific overrides -->
    <?php if ($current_role !== 'dashboard' && file_exists(__DIR__ . "/../assets/css/{$current_role}.css")): ?>
        <link rel="stylesheet" href="<?php echo $base_url; ?>assets/css/<?php echo $current_role; ?>.css">
    <?php endif; ?>
</head>
<body>
    <!-- Global Page Loader: tampil saat halaman/menu dimuat & saat form (termasuk upload) disubmit -->
    <div id="page-loader" class="page-loader">
        <div class="page-loader-box">
            <img src="<?php echo $base_url; ?>assets/img/logo.png" alt="PT Aksara Riksa Perdana" class="page-loader-logo">
            <div class="page-loader-spinner"></div>
            <p class="page-loader-text" id="page-loader-text">Memuat halaman...</p>
        </div>
    </div>

    <div id="app-layout">