<?php
// client/riwayat.php
$page_title = "Riwayat Pemeriksaan";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
?>

<main class="main-content">
    <div class="card-box">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
            <h5 class="mb-0 fw-bold">Riwayat Pemeriksaan Objek K3</h5>
            <div class="d-flex gap-2">
                <div class="search-box-container" style="width: 220px;">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-box" placeholder="Cari riwayat...">
                </div>
                <select class="select-custom" style="width: 180px;">
                    <option value="">Semua Hasil</option>
                    <option value="Layak">Layak</option>
                    <option value="Layak Bersyarat">Layak Bersyarat</option>
                    <option value="Tidak Layak">Tidak Layak</option>
                </select>
            </div>
        </div>

        <div class="table-responsive-custom">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Objek K3</th>
                        <th>Jenis Pemeriksaan</th>
                        <th>Tanggal Pemeriksaan</th>
                        <th>No. Laporan</th>
                        <th>Hasil</th>
                        <th style="text-align: center;">Sertifikat</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>Overhead Crane 5 Ton</td>
                        <td>Pemeriksaan Berkala</td>
                        <td>28 Juni 2026</td>
                        <td>LAP-2026-0871</td>
                        <td><span class="badge-success">Layak</span></td>
                        <td style="text-align: center;">
                            <button class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;">
                                <i class="bi bi-download"></i> PDF
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td>Boiler Uap</td>
                        <td>Pemeriksaan Berkala</td>
                        <td>2 Juli 2026</td>
                        <td>LAP-2026-0902</td>
                        <td><span class="badge-warning">Layak Bersyarat</span></td>
                        <td style="text-align: center;">
                            <button class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;">
                                <i class="bi bi-download"></i> PDF
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td>3</td>
                        <td>Panel Distribusi Listrik</td>
                        <td>Pemeriksaan Ulang</td>
                        <td>18 Juni 2026</td>
                        <td>LAP-2026-0844</td>
                        <td><span class="badge-danger">Tidak Layak</span></td>
                        <td style="text-align: center;">
                            <button class="btn-primary-custom" style="height:32px; padding:0 12px; font-size:0.8rem;">
                                <i class="bi bi-download"></i> PDF
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pagination-custom">
            <span class="text-muted fs-7">Menampilkan 1-3 dari 12 data</span>
            <ul class="pagination-pages">
                <li class="pagination-item disabled"><span><i class="bi bi-chevron-left"></i></span></li>
                <li class="pagination-item active"><span>1</span></li>
                <li class="pagination-item"><a href="#">2</a></li>
                <li class="pagination-item"><a href="#">3</a></li>
                <li class="pagination-item"><a href="#"><i class="bi bi-chevron-right"></i></a></li>
            </ul>
        </div>
    </div>
</main>

<?php
include "../includes/footer.php";
?>
