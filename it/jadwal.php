<?php
// it/jadwal.php
$page_title = "Jadwal Pemeriksaan Lapangan";
include "../includes/header.php";
include "../includes/sidebar.php";
include "../includes/topbar.php";
require_once "../config/koneksi.php";

// Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'it') {
    header("Location: ../login.php");
    exit;
}

$current_user_id = $_SESSION['user_id'];

// Get the corresponding Sertifikat_Ahli ID for this user
try {
    $stmtAhli = $conn->prepare("SELECT id FROM Sertifikat_Ahli WHERE user_id = :user_id LIMIT 1");
    $stmtAhli->execute(['user_id' => $current_user_id]);
    $ahli_k3_id = $stmtAhli->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $ahli_k3_id = 0;
}

// Fetch only this Ahli K3's schedules
$jadwals = [];
if ($ahli_k3_id > 0) {
    try {
        $stmtJadwal = $conn->prepare("
            SELECT jp.*, dk.nama_perusahaan, sa.nama_lengkap AS nama_ahli, sa.tingkat_ahli, sa.bidang_keahlian, u.nama_lengkap AS nama_admin 
            FROM Jadwal_Pemeriksaan jp
            JOIN Data_Klien dk ON jp.klien_id = dk.id
            JOIN Sertifikat_Ahli sa ON jp.ahli_k3_id = sa.id
            JOIN Users u ON jp.dijadwalkan_oleh = u.id
            WHERE jp.ahli_k3_id = :ahli_id
            ORDER BY jp.tanggal DESC, jp.jam_mulai DESC
        ");
        $stmtJadwal->execute(['ahli_id' => $ahli_k3_id]);
        $jadwals = $stmtJadwal->fetchAll();
    } catch (PDOException $e) {
        $jadwals = [];
    }
}

$today_str = date('Y-m-d');
?>

<main class="main-content">
    <div class="row g-4">
        <!-- Kalender Jadwal Riksa (read-only) -->
        <div class="col-lg-8">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Kalender Jadwal Riksa</h5>
                    <div class="table-toolbar-actions">
                        <button type="button" class="btn-secondary-custom cal-nav-btn" onclick="changeMonth(-1)">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <span id="calendarMonthLabel" class="fw-semibold"
                            style="min-width:150px; text-align:center; display:inline-block;"></span>
                        <button type="button" class="btn-secondary-custom cal-nav-btn" onclick="changeMonth(1)">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </div>

                <div class="calendar-weekdays">
                    <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                </div>
                <div class="calendar-grid" id="calendarGrid"></div>

                <div class="calendar-legend">
                    <span class="legend-dot"></span> Ada jadwal riksa &mdash; nama klien ditampilkan langsung pada
                    tanggal
                </div>
            </div>
        </div>

        <!-- Daftar Riksa Aktif (read-only) -->
        <div class="col-lg-4">
            <div class="card-box">
                <div class="table-toolbar">
                    <h5 class="table-toolbar-title fw-bold">Daftar Riksa Aktif</h5>
                </div>
                <div class="riksa-subtitle" id="selectedDateLabel"></div>

                <div id="daftarRiksaAktif" class="daftar-riksa-list"></div>
            </div>
        </div>
    </div>
</main>

<script>
    // Data jadwal milik ahli K3 ini saja, dipakai untuk kalender & daftar riksa aktif (read-only)
    const jadwalData = <?= json_encode($jadwals) ?>;
    const todayStr = <?= json_encode($today_str) ?>;

    const namaBulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    const namaHari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    let calYear, calMonth, selectedDate;

    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination('tabelJadwalAhli', 10);

        const now = new Date();
        calYear = now.getFullYear();
        calMonth = now.getMonth(); // 0-based
        selectedDate = todayStr;

        renderCalendar();
        renderDaftarAktif(selectedDate);
    });

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    function getDateRange(start, end) {
        const dates = [];
        let cur = new Date(start + 'T00:00:00');
        const last = new Date((end || start) + 'T00:00:00');
        while (cur <= last) {
            dates.push(cur.getFullYear() + '-' + pad2(cur.getMonth() + 1) + '-' + pad2(cur.getDate()));
            cur.setDate(cur.getDate() + 1);
        }
        return dates;
    }

    function changeMonth(dir) {
        calMonth += dir;
        if (calMonth < 0) { calMonth = 11; calYear--; }
        if (calMonth > 11) { calMonth = 0; calYear++; }
        renderCalendar();
    }

    function renderCalendar() {
        const grid = document.getElementById('calendarGrid');
        const label = document.getElementById('calendarMonthLabel');
        label.textContent = namaBulan[calMonth] + ' ' + calYear;
        grid.innerHTML = '';

        // Kelompokkan jadwal berdasarkan tanggal, agar tiap tanggal bisa menampilkan nama klien pemeriksaannya
        const eventsByDate = {};
        jadwalData.forEach(j => {
            getDateRange(j.tanggal, j.tanggal_selesai).forEach(dateStr => {
                if (!eventsByDate[dateStr]) eventsByDate[dateStr] = [];
                eventsByDate[dateStr].push(j);
            });
        });
        const firstOfMonth = new Date(calYear, calMonth, 1);
        // Senin = 0 ... Minggu = 6
        let startOffset = (firstOfMonth.getDay() + 6) % 7;
        const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
        const maxShow = 2;

        for (let i = 0; i < startOffset; i++) {
            const empty = document.createElement('div');
            empty.className = 'calendar-day empty';
            grid.appendChild(empty);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = calYear + '-' + pad2(calMonth + 1) + '-' + pad2(d);
            const dayEvents = eventsByDate[dateStr] || [];

            const cell = document.createElement('div');
            cell.className = 'calendar-day';
            if (dateStr === todayStr) cell.classList.add('today');
            if (dateStr === selectedDate) cell.classList.add('selected');

            let eventsHtml = '';
            if (dayEvents.length > 0) {
                eventsHtml = '<div class="day-events">' +
                    dayEvents.slice(0, maxShow).map(ev => `<div class="day-event-item">${escapeHtml(ev.nama_perusahaan)}</div>`).join('') +
                    (dayEvents.length > maxShow ? `<div class="day-event-more">+${dayEvents.length - maxShow} lainnya</div>` : '') +
                    '</div>';
            }

            cell.innerHTML = `<div class="day-number">${d}</div>${eventsHtml}`;
            cell.onclick = () => selectDate(dateStr);
            grid.appendChild(cell);
        }
    }

    function selectDate(dateStr) {
        selectedDate = dateStr;
        renderCalendar();
        renderDaftarAktif(dateStr);
    }

    function renderDaftarAktif(dateStr) {
        const list = document.getElementById('daftarRiksaAktif');
        const label = document.getElementById('selectedDateLabel');

        const dObj = new Date(dateStr + 'T00:00:00');
        const formatted = dObj.getDate() + ' ' + namaBulan[dObj.getMonth()] + ' ' + dObj.getFullYear();
        label.textContent = 'Inspeksi untuk tanggal ' + formatted;

        const items = jadwalData
            .filter(j => getDateRange(j.tanggal, j.tanggal_selesai).includes(dateStr))
            .sort((a, b) => a.jam_mulai.localeCompare(b.jam_mulai));

        if (items.length === 0) {
            list.innerHTML = '<div class="riksa-empty"><i class="bi bi-calendar-x fs-3 d-block mb-2"></i>Tidak ada jadwal riksa pada tanggal ini.</div>';
            return;
        }

        // Read-only: tanpa tombol Edit
        list.innerHTML = items.map(j => {
            let badgeClass = 'badge-warning';
            if (j.status === 'Selesai') badgeClass = 'badge-success';
            if (j.status === 'Dibatalkan') badgeClass = 'badge-danger';
            const jamSelesai = j.jam_selesai ? j.jam_selesai.substring(0, 5) : 'Selesai';

            // Ambil nama tim support dari catatan (format: [Tim Support: A, B, C])
            let supportText = 'Unknown';
            if (j.catatan) {
                const match = j.catatan.match(/\[Tim Support:\s*(.+?)\]/);
                if (match && match[1].trim()) {
                    supportText = match[1].trim();
                }
            }
            const leadExpertText = j.nama_ahli ? escapeHtml(j.nama_ahli) + (j.tingkat_ahli ? ' (' + escapeHtml(j.tingkat_ahli) + ')' : '') : 'Unknown';

            return `
    <div class="riksa-card">
        <div class="riksa-jam">${j.jam_mulai.substring(0, 5)} - ${jamSelesai}</div>
        <div class="riksa-client">${escapeHtml(j.nama_perusahaan)}</div>
        <div class="riksa-ahli"><span class="riksa-label">Lead Expert</span><span class="riksa-value">${leadExpertText}</span></div>
        <div class="riksa-ahli"><span class="riksa-label">Support</span><span class="riksa-value">${escapeHtml(supportText)}</span></div>
        <div class="riksa-lokasi">${j.lokasi ? escapeHtml(j.lokasi) : '-'}</div>
        <div class="riksa-dibuat">Dibuat oleh: ${escapeHtml(j.nama_admin || '-')}</div>
    </div>
`;
        }).join('');
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
</script>

<?php
include "../includes/footer.php";
?>