<?php
// client/dashboard.php
$page_title = "Dashboard Portal Mitra";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/navbar.php";
?>

<main class="main-content">
    <!-- Stat Cards Section -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Pengajuan Aktif</span>
                    <span class="stat-card-value">2 Berkas</span>
                </div>
                <div class="stat-card-icon warning">
                    <i class="bi bi-file-earmark-arrow-up-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Sertifikasi Selesai</span>
                    <span class="stat-card-value">15 Dokumen</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-patch-check-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Notifikasi Belum Dibaca</span>
                    <span class="stat-card-value">3</span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-bell-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Status Kerja Sama</span>
                    <span class="stat-card-value">Aktif</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-handshake-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Tables & Activity -->
        <div class="col-lg-8 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Riwayat Pengajuan & Layanan K3</h5>
                
                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Jenis Layanan</th>
                                <th>Tanggal Pengajuan</th>
                                <th>No Registrasi</th>
                                <th>Status</th>
                                <th style="text-align: center;">Unduh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>Sertifikasi Alat Angkat Angkut (Forklift)</td>
                                <td>10 Juli 2026</td>
                                <td>REG-2026-0912</td>
                                <td><span class="badge-success">Terbit</span></td>
                                <td style="text-align: center;">
                                    <button class="btn-primary-custom" style="height:32px; padding: 0 12px; font-size:0.8rem;"><i class="bi bi-download"></i> PDF</button>
                                </td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>Pemeriksaan Kesehatan Tenaga Kerja (MCU)</td>
                                <td>14 Juli 2026</td>
                                <td>REG-2026-0955</td>
                                <td><span class="badge-warning">Proses</span></td>
                                <td style="text-align: center;">
                                    <span class="text-muted fs-7">Dalam Proses</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sidebar Widgets -->
        <div class="col-lg-4 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Hubungi Support Aksara</h5>
                <p class="text-muted fs-7">Membutuhkan bantuan teknis atau informasi mengenai pengajuan layanan sertifikasi?</p>
                <button class="btn-primary-custom w-100">
                    <i class="bi bi-chat-right-text-fill"></i> Buka Chat Support
                </button>
            </div>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>
