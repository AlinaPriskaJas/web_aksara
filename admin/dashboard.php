<?php
// admin/dashboard.php
$page_title = "Dashboard Admin";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/navbar.php";
?>

<main class="main-content">
    <!-- Stat Cards Section -->
    <div class="row g-4 mb-4">
        <!-- Card 1: Karyawan -->
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Jumlah Karyawan</span>
                    <span class="stat-card-value">1,248</span>
                </div>
                <div class="stat-card-icon">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
        </div>

        <!-- Card 2: Approval -->
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Jumlah Approval</span>
                    <span class="stat-card-value">32</span>
                </div>
                <div class="stat-card-icon warning">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
            </div>
        </div>

        <!-- Card 3: Surat -->
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Jumlah Surat</span>
                    <span class="stat-card-value">145</span>
                </div>
                <div class="stat-card-icon success">
                    <i class="bi bi-envelope-paper-fill"></i>
                </div>
            </div>
        </div>

        <!-- Card 4: Kendaraan -->
        <div class="col-xl-3 col-md-6 col-12">
            <div class="stat-card">
                <div class="stat-card-info">
                    <span class="stat-card-title">Jumlah Kendaraan</span>
                    <span class="stat-card-value">18</span>
                </div>
                <div class="stat-card-icon danger">
                    <i class="bi bi-car-front-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">
        <!-- Left Column: Tables & Forms -->
        <div class="col-lg-8 col-12">
            <!-- Table Box -->
            <div class="card-box">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h5 class="mb-0 fw-bold">Daftar Pengajuan Terbaru</h5>
                    <div class="d-flex gap-2">
                        <div class="search-box-container" style="width: 200px;">
                            <i class="bi bi-search"></i>
                            <input type="text" class="search-box" placeholder="Cari...">
                        </div>
                        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#tambahModal">
                            <i class="bi bi-plus-lg"></i> Tambah
                        </button>
                    </div>
                </div>

                <div class="table-responsive-custom">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Karyawan</th>
                                <th>Departemen</th>
                                <th>Kategori</th>
                                <th>Tanggal</th>
                                <th>Status</th>
                                <th style="text-align: center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>Bambang Pamungkas</td>
                                <td>Operasional</td>
                                <td>Peminjaman Mobil</td>
                                <td>15 Juli 2026</td>
                                <td><span class="badge-warning">Pending</span></td>
                                <td style="text-align: center;">
                                    <button class="btn-primary-custom"
                                        style="height:32px; padding: 0 12px; font-size:0.8rem;">Setujui</button>
                                </td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td>Dewi Sartika</td>
                                <td>Sumber Daya Manusia</td>
                                <td>Pengajuan Cuti</td>
                                <td>14 Juli 2026</td>
                                <td><span class="badge-success">Disetujui</span></td>
                                <td style="text-align: center;">
                                    <span class="text-muted fs-7">-</span>
                                </td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td>Joko Widodo</td>
                                <td>Teknologi Informasi</td>
                                <td>Perbaikan Laptop</td>
                                <td>12 Juli 2026</td>
                                <td><span class="badge-danger">Ditolak</span></td>
                                <td style="text-align: center;">
                                    <span class="text-muted fs-7">-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Custom Pagination -->
                <div class="pagination-custom">
                    <span class="text-muted fs-7">Menampilkan 1-3 dari 3 data</span>
                    <ul class="pagination-pages">
                        <li class="pagination-item disabled"><span><i class="bi bi-chevron-left"></i></span></li>
                        <li class="pagination-item active"><span>1</span></li>
                        <li class="pagination-item"><a href="#">2</a></li>
                        <li class="pagination-item"><a href="#"><i class="bi bi-chevron-right"></i></a></li>
                    </ul>
                </div>
            </div>

            <!-- Form Box -->
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Form Input Pengajuan</h5>
                <form action="#" method="POST">
                    <div class="row g-4">
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Nama Lengkap</label>
                            <input type="text" class="form-control-custom" placeholder="Masukkan nama lengkap...">
                        </div>
                        <div class="col-md-6 col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Departemen</label>
                            <select class="select-custom">
                                <option value="">-- Pilih Departemen --</option>
                                <option value="hrd">HRD</option>
                                <option value="it">IT</option>
                                <option value="ops">Operasional</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold fs-7 mb-2">Keperluan</label>
                            <textarea class="textarea-custom"
                                placeholder="Tuliskan alasan pengajuan secara lengkap..."></textarea>
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-3 mt-4">
                            <button type="reset" class="btn-secondary-custom">Reset</button>
                            <button type="submit" class="btn-primary-custom">Kirim Form</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column: Status Summary & System Info -->
        <div class="col-lg-4 col-12">
            <div class="card-box">
                <h5 class="mb-4 fw-bold">Ringkasan Sistem</h5>

                <div class="alert alert-success-custom mb-3">
                    <i class="bi bi-info-circle-fill fs-5"></i>
                    <div>
                        <strong>Database Terhubung</strong><br>
                        Status sinkronisasi database server utama berjalan dengan baik.
                    </div>
                </div>

                <div class="mb-3">
                    <span class="text-secondary fs-7 d-block mb-1">Penyimpanan Gudang</span>
                    <div class="progress" style="height: 8px; border-radius: 4px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: 70%" aria-valuenow="70"
                            aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <span class="fs-7 text-muted">Terpakai: 700 / 1000m²</span>
                        <span class="fs-7 fw-bold text-success">70%</span>
                    </div>
                </div>

                <div class="mb-4">
                    <span class="text-secondary fs-7 d-block mb-1">Penggunaan Bahan Bakar</span>
                    <div class="progress" style="height: 8px; border-radius: 4px;">
                        <div class="progress-bar bg-danger" role="progressbar" style="width: 92%" aria-valuenow="92"
                            aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <span class="fs-7 text-muted">Limit Bulanan: 920 / 1000L</span>
                        <span class="fs-7 fw-bold text-danger">92%</span>
                    </div>
                </div>

                <button class="btn-danger-custom w-100">
                    <i class="bi bi-exclamation-triangle-fill"></i> Laporkan Masalah Server
                </button>
            </div>
        </div>
    </div>
</main>

<!-- Modal Reusable Template -->
<div class="modal fade modal-custom" id="tambahModal" tabindex="-1" aria-labelledby="tambahModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="tambahModalLabel">Tambah Data Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form>
                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7 mb-2">Nama Karyawan</label>
                        <input type="text" class="form-control-custom" placeholder="Nama Karyawan">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold fs-7 mb-2">Kategori Pengajuan</label>
                        <select class="select-custom">
                            <option>Peminjaman Mobil</option>
                            <option>Cuti Kerja</option>
                            <option>Perbaikan Aset</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn-primary-custom">Simpan Data</button>
            </div>
        </div>
    </div>
</div>

<?php
include "../includes/footer.php";
?>