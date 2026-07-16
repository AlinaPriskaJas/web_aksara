<?php
// ahlik3/dashboard.php
$page_title = "Dashboard Keselamatan Kerja (K3)";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <!-- Stat Cards Section -->
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Zero Accident Days</span>
                    <span class="stat-card-value">365 Hari</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-shield-heart-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Jadwal Inspeksi</span>
                    <span class="stat-card-value">4 Aktif</span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-calendar-event-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Temuan Bahaya</span>
                    <span class="stat-card-value">2 Baru</span>
                </div>
                <div class="stat-card-icon danger">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Nilai Kepatuhan</span>
                    <span class="stat-card-value">98.2%</span>
                </div>
                <div class="stat-card-icon warning">
                    <i class="bi bi-award-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Tables & Activity -->
        <div class="col-lg-8 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Jadwal Inspeksi Proyek Mendatang</h5>

                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Lokasi</th>
                                <th>Tanggal Pelaksanaan</th>
                                <th>Petugas Lapangan</th>
                                <th>Status</th>
                                <th style="text-align: center;">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>Gudang Cikarang Barat</td>
                                <td>18 Juli 2026</td>
                                <td>Hendra K3</td>
                                <td><span class="badge-warning">Dijadwalkan</span></td>
                                <td style="text-align: center;">
                                    <button class="btn-primary-custom"
                                        style="height:32px; padding: 0 12px; font-size:0.8rem;">Mulai</button>
                                </td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>Pabrik Utama Karawang</td>
                                <td>22 Juli 2026</td>
                                <td>Hendra K3</td>
                                <td><span class="badge-warning">Dijadwalkan</span></td>
                                <td style="text-align: center;">
                                    <button class="btn-primary-custom"
                                        style="height:32px; padding: 0 12px; font-size:0.8rem;">Mulai</button>
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
                <h5 class="mb-4 fw-bold">Rekomendasi HSE Terbaru</h5>
                <div class="alert alert-danger-custom mb-3">
                    <i class="bi bi-exclamation-octagon-fill fs-5"></i>
                    <div>
                        <strong>APAR Kedaluwarsa</strong><br>
                        Temuan di Gedung B Lantai 2. Segera lakukan pengisian ulang.
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>