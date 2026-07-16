<?php
// it/dashboard.php
$page_title = "Dashboard IT Support";
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
                    <span class="stat-card-title">Server Uptime</span>
                    <span class="stat-card-value">99.98%</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-hdd-network-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Users</span>
                    <span class="stat-card-value">1,402</span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Storage Terpakai</span>
                    <span class="stat-card-value">64%</span>
                </div>
                <div class="stat-card-icon warning">
                    <i class="bi bi-server"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Ancaman Keamanan</span>
                    <span class="stat-card-value">0</span>
                </div>
                <div class="stat-card-icon danger">
                    <i class="bi bi-shield-fill-check"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Tables & Activity -->
        <div class="col-lg-8 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Log Aktivitas Sistem Terbaru</h5>

                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Pengguna</th>
                                <th>Modul</th>
                                <th>Tindakan</th>
                                <th>IP Address</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>Aditya Pratama (Admin)</td>
                                <td>Stock Gudang</td>
                                <td>Menambahkan Barang #1923</td>
                                <td>192.168.1.45</td>
                                <td>19:42</td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>Hendra K3 (Ahli K3)</td>
                                <td>Jadwal K3</td>
                                <td>Mengubah Inspeksi Proyek A</td>
                                <td>192.168.1.12</td>
                                <td>18:15</td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td>Budi Hartono (Guest)</td>
                                <td>Auth</td>
                                <td>Login Berhasil</td>
                                <td>192.168.1.100</td>
                                <td>17:30</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sidebar Widgets -->
        <div class="col-lg-4 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Backup Database Otomatis</h5>

                <div class="border rounded p-3 mb-3 bg-white">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="fw-bold fs-7">Backup Harian Cloud</span>
                        <span class="badge-success">Selesai</span>
                    </div>
                    <span class="text-secondary fs-7 d-block mb-3">Terakhir: Hari ini pukul 02:00 WIB</span>
                    <button class="btn-primary-custom w-100" style="height:36px;">
                        <i class="bi bi-cloud-arrow-down-fill"></i> Backup Sekarang
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>