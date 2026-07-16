<?php
// direksi/dashboard.php
$page_title = "Dashboard Direksi";
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
                    <span class="stat-card-title">Efisiensi Operasional</span>
                    <span class="stat-card-value">94.8%</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-graph-up"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Laporan Keuangan</span>
                    <span class="stat-card-value">IDR 2.4B</span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-wallet2"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Persetujuan Direksi</span>
                    <span class="stat-card-value">5 Pending</span>
                </div>
                <div class="stat-card-icon warning">
                    <i class="bi bi-file-earmark-check-fill"></i>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Total Project K3</span>
                    <span class="stat-card-value">12 Aktif</span>
                </div>
                <div class="stat-card-icon danger">
                    <i class="bi bi-activity"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Tables & Documents -->
        <div class="col-lg-8 col-12">
            <div class="card-box">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h5 class="mb-0 fw-bold">Monitoring Laporan Terkini</h5>
                    <button class="btn-primary-custom">
                        <i class="bi bi-file-earmark-arrow-down"></i> Unduh Semua
                    </button>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Judul Laporan</th>
                                <th>Tanggal Rilis</th>
                                <th>Pembuat</th>
                                <th>Kategori</th>
                                <th style="text-align: center;">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>Laporan Audit K3 Semester I</td>
                                <td>12 Juli 2026</td>
                                <td>Hendra K3</td>
                                <td>Keselamatan Kerja</td>
                                <td style="text-align: center;">
                                    <button class="btn-secondary-custom"
                                        style="height:32px; padding: 0 12px; font-size:0.8rem;">Lihat PDF</button>
                                </td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>Rekapitulasi Absensi & Lembur Juni</td>
                                <td>05 Juli 2026</td>
                                <td>Aditya Pratama</td>
                                <td>SDM & Keuangan</td>
                                <td style="text-align: center;">
                                    <button class="btn-secondary-custom"
                                        style="height:32px; padding: 0 12px; font-size:0.8rem;">Lihat PDF</button>
                                </td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td>Evaluasi Vendor Transportasi 2026</td>
                                <td>28 Juni 2026</td>
                                <td>Budi Hartono</td>
                                <td>Operasional</td>
                                <td style="text-align: center;">
                                    <button class="btn-secondary-custom"
                                        style="height:32px; padding: 0 12px; font-size:0.8rem;">Lihat PDF</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sidebar widget -->
        <div class="col-lg-4 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Persetujuan Cepat</h5>
                <p class="text-muted fs-7">Berikut berkas penting yang memerlukan tanda tangan digital dari Anda hari
                    ini:</p>

                <div class="border rounded p-3 mb-3" style="background-color: var(--bg-body);">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="fw-bold fs-7">Rekomendasi Anggaran K3</span>
                        <span class="badge-warning">Penting</span>
                    </div>
                    <span class="text-secondary fs-7 d-block mb-3">Pengaju: Hendra K3 (14 Juli 2026)</span>
                    <div class="d-flex gap-2 justify-content-end">
                        <button class="btn-danger-custom"
                            style="height: 32px; padding: 0 12px; font-size: 0.8rem;">Tolak</button>
                        <button class="btn-primary-custom"
                            style="height: 32px; padding: 0 12px; font-size: 0.8rem;">Tanda Tangani</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>