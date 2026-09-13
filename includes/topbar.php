<?php
// includes/topbar.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/avatar_helper.php';

// Kalau $conn belum tersedia (misal file ini dipanggil langsung via fetch AJAX,
// bukan lewat include di halaman lain yang sudah require koneksi.php duluan).
if (!isset($conn)) {
    require_once __DIR__ . '/../config/koneksi.php';
}

// TODO: ganti dengan user_id dari sesi login sebenarnya setelah proses_login.php terhubung penuh.
$topbar_user_id = $_SESSION['user_id'] ?? 1;

// ================== HANDLE REQUEST AJAX: TANDAI NOTIFIKASI DIBACA ==================
// Ditaruh di paling atas SEBELUM ada output HTML apa pun, supaya bisa langsung
// balas JSON lalu exit tanpa ikut nge-render topbar.
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'mark_read') {
        $notif_id = (int) ($_GET['id'] ?? 0);

        if ($notif_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID notifikasi tidak valid.']);
            exit;
        }

        try {
            // Guard: hanya boleh menandai notifikasi milik user yang sedang login
            $stmt = $conn->prepare("
                UPDATE Notifikasi
                SET sudah_dibaca = 1
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute([
                ':id' => $notif_id,
                ':user_id' => $topbar_user_id,
            ]);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menandai notifikasi: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'mark_all_read') {
        try {
            $stmt = $conn->prepare("
                UPDATE Notifikasi
                SET sudah_dibaca = 1
                WHERE user_id = :user_id AND sudah_dibaca = 0
            ");
            $stmt->execute([':user_id' => $topbar_user_id]);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menandai semua notifikasi: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'get_count') {
        try {
            $stmtCount = $conn->prepare("
            SELECT COUNT(*) AS jml FROM Notifikasi
            WHERE user_id = :user_id AND sudah_dibaca = 0
        ");
            $stmtCount->execute([':user_id' => $topbar_user_id]);
            $jml = (int) ($stmtCount->fetch()['jml'] ?? 0);

            $stmtList = $conn->prepare("
            SELECT id, judul, pesan, modul_terkait, ref_id, sudah_dibaca, waktu_kirim
            FROM Notifikasi
            WHERE user_id = :user_id
            ORDER BY waktu_kirim DESC
            LIMIT 8
        ");
            $stmtList->execute([':user_id' => $topbar_user_id]);
            $rows = $stmtList->fetchAll();

            $list = array_map(function ($n) use ($conn) {
                return [
                    'id' => (int) $n['id'],
                    'judul' => $n['judul'],
                    'pesan' => $n['pesan'],
                    'sudah_dibaca' => (bool) $n['sudah_dibaca'],
                    'waktu' => topbar_waktu_relatif($n['waktu_kirim']),
                    'url' => topbar_link_notif($conn, $n['modul_terkait'] ?? '', $n['ref_id'] ?? null, $_SESSION['role'] ?? '', $GLOBALS['base_url'] ?? './'),
                ];
            }, $rows);

            echo json_encode(['success' => true, 'count' => $jml, 'list' => $list]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'count' => 0, 'list' => []]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Permintaan tidak dikenali.']);
    exit;
}

// ================== RENDER TOPBAR NORMAL (request biasa, bukan AJAX) ==================

$topbar_nama = ($_SESSION['role'] ?? '') === 'client' && !empty($_SESSION['nama_perusahaan'])
    ? $_SESSION['nama_perusahaan']
    : ($_SESSION['nama_lengkap'] ?? 'Pengguna');

$topbar_foto = $_SESSION['foto_profil'] ?? null;
$topbar_base = $base_url ?? './';

// ================== AMBIL NOTIFIKASI UNTUK USER YANG SEDANG LOGIN ==================
$topbar_notif_list = [];
$topbar_notif_count = 0;

if (isset($conn)) {
    try {
        $stmtNotif = $conn->prepare("
            SELECT id, judul, pesan, modul_terkait, ref_id, sudah_dibaca, waktu_kirim
            FROM Notifikasi
            WHERE user_id = :user_id
            ORDER BY waktu_kirim DESC
            LIMIT 8
        ");
        $stmtNotif->execute([':user_id' => $topbar_user_id]);
        $topbar_notif_list = $stmtNotif->fetchAll();

        $stmtCount = $conn->prepare("
            SELECT COUNT(*) AS jml FROM Notifikasi
            WHERE user_id = :user_id AND sudah_dibaca = 0
        ");
        $stmtCount->execute([':user_id' => $topbar_user_id]);
        $topbar_notif_count = (int) ($stmtCount->fetch()['jml'] ?? 0);
    } catch (PDOException $e) {
        $topbar_notif_list = [];
        $topbar_notif_count = 0;
    }
}

// Helper: ubah waktu jadi "X menit lalu" dsb, biar ringkas
function topbar_waktu_relatif(string $waktu): string
{
    $detik = time() - strtotime($waktu);
    if ($detik < 60)
        return 'Baru saja';
    if ($detik < 3600)
        return floor($detik / 60) . ' menit lalu';
    if ($detik < 86400)
        return floor($detik / 3600) . ' jam lalu';
    if ($detik < 172800)
        return 'Kemarin';
    return date('d M Y', strtotime($waktu));
}

// Helper: tentukan URL tujuan saat notifikasi diklik, berdasarkan modul & role penerima
// Helper: mapping jenis_cuti (nilai di tabel Cuti) -> slug tab di cuti.php
function topbar_tab_from_jenis_cuti(?string $jenis_cuti): string
{
    $map = [
        'Cuti Tahunan' => 'tahunan',
        'Cuti Khusus' => 'khusus',
        'Izin Sakit' => 'sakit',
        'Cuti Sakit' => 'sakit', // jaga-jaga kalau label baru dipakai di tempat lain
    ];
    return $map[$jenis_cuti] ?? 'tahunan';
}

// Helper: tentukan URL tujuan saat notifikasi diklik, berdasarkan modul & role penerima
function topbar_link_notif(PDO $conn, string $modul, ?int $ref_id, string $role, string $base): string
{
    $role_dir = match ($role) {
        'admin' => 'admin',
        'direksi' => 'direksi',
        'ahli_k3' => 'ahlik3',
        'it' => 'it',
        default => 'admin',
    };

    // Direksi tidak punya halaman cuti.php sendiri — dia approve lewat approval.php
    if ($role === 'direksi' && in_array($modul, ['cuti', 'reimburse', 'kendaraan', 'surat'])) {
        // Surat Keluar diproses di tab "Approval Surat", sisanya di tab "Pengajuan Umum".
        $tabTujuan = $modul === 'surat' ? 'surat' : 'umum';

        $queryTujuan = ['tab' => $tabTujuan];
        if ($ref_id) {
            $queryTujuan['highlight'] = $ref_id;
            $queryTujuan['modul'] = $modul;
        }

        return $base . 'direksi/approval.php?' . http_build_query($queryTujuan);
    }

    // Cuti: arahkan ke tab yang sesuai dengan jenis cutinya + sorot barisnya
    if ($modul === 'cuti') {
        $tab = 'tahunan';
        if ($ref_id) {
            try {
                $s = $conn->prepare("SELECT jenis_cuti FROM Cuti WHERE id = :id LIMIT 1");
                $s->execute([':id' => $ref_id]);
                $jenis = $s->fetchColumn();
                if ($jenis) {
                    $tab = topbar_tab_from_jenis_cuti($jenis);
                }
            } catch (PDOException $e) {
                // biarkan default 'tahunan' kalau query gagal
            }
        }
        return $base . $role_dir . '/cuti.php?tab=' . $tab . ($ref_id ? '&highlight=' . $ref_id : '');
    }

    // Jadwal Pemeriksaan: halaman jadwal.php hanya dimiliki role admin & ahli_k3.
    // Role lain (direksi, it, dst) yang kebagian notif broadcast jadwal
    // tidak punya halaman ini, jadi diarahkan ke dashboard saja supaya tidak 404.
    if ($modul === 'jadwal') {
        if (in_array($role, ['admin', 'ahli_k3'], true)) {
            return $base . $role_dir . '/jadwal.php' . ($ref_id ? '?highlight=' . $ref_id : '');
        }
        return $base . $role_dir . '/dashboard.php';
    }

    return match ($modul) {
        'reimburse' => $base . $role_dir . '/reimburse.php',
        'kendaraan' => $base . $role_dir . '/transportasi.php',
        'surat' => $base . $role_dir . '/surat.php',
        'insiden' => $base . $role_dir . '/insiden.php',
        'absensi' => $base . $role_dir . '/absensi.php',
        default => $base . $role_dir . '/dashboard.php',
    };
}

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
            <div class="notif-wrapper" style="position: relative;">
                <button class="notification-btn" id="notifBtn" aria-label="Notifications">
                    <i class="bi bi-bell"></i>
                    <span class="notification-dot" id="notifBadge"
                        style="<?= $topbar_notif_count > 0 ? '' : 'display:none;' ?>"><?= $topbar_notif_count > 9 ? '9+' : $topbar_notif_count ?></span>
                </button>

                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-dropdown-header">
                        <span>Notifikasi</span>
                        <?php if ($topbar_notif_count > 0): ?>
                            <button type="button" class="notif-mark-all" id="notifMarkAll">Tandai semua dibaca</button>
                        <?php endif; ?>
                    </div>
                    <div class="notif-dropdown-body">
                        <?php if (empty($topbar_notif_list)): ?>
                            <div class="notif-empty">Belum ada notifikasi.</div>
                        <?php else: ?>
                            <?php foreach ($topbar_notif_list as $n): ?>
                                <div class="notif-item <?= !$n['sudah_dibaca'] ? 'notif-item-unread' : '' ?>"
                                    data-id="<?= (int) $n['id'] ?>" data-dibaca="<?= $n['sudah_dibaca'] ? '1' : '0' ?>"
                                    data-url="<?= htmlspecialchars(topbar_link_notif($conn, $n['modul_terkait'] ?? '', $n['ref_id'] ?? null, $_SESSION['role'] ?? '', $topbar_base)) ?>">
                                    <div class="notif-item-title"><?= htmlspecialchars($n['judul']) ?></div>
                                    <div class="notif-item-pesan"><?= htmlspecialchars($n['pesan']) ?></div>
                                    <div class="notif-item-waktu"><?= topbar_waktu_relatif($n['waktu_kirim']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <audio id="notifSound" src="<?= htmlspecialchars($topbar_base) ?>assets/sounds/notif.mp3"
                preload="auto"></audio>

            <!-- User Avatar -->
            <a href="profile.php">
                <?= arp_avatar_html($topbar_nama, $topbar_foto, $topbar_base, 40, 'topbar-avatar') ?>
            </a>
        </div>
    </header>

    <style>
        .notification-btn {
            position: relative;
        }

        .notification-dot {
            position: absolute;
            top: -2px;
            right: -6px;
            min-width: 16px;
            height: 16px;
            padding: 0 4px;
            border-radius: 999px;
            background: #ef4444;
            border: 2px solid #fff;
            box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.25);
            color: #fff;
            font-size: 0.62rem;
            font-weight: 700;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notif-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 320px;
            max-width: 90vw;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            z-index: 1000;
            overflow: hidden;
        }

        .notif-dropdown.show {
            display: block;
        }

        .notif-dropdown-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-weight: 700;
            font-size: 0.85rem;
            color: #1e293b;
        }

        .notif-mark-all {
            background: none;
            border: none;
            color: #4338ca;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
        }

        .notif-mark-all:hover {
            text-decoration: underline;
        }

        .notif-dropdown-body {
            max-height: 340px;
            overflow-y: auto;
        }

        .notif-empty {
            padding: 24px 14px;
            text-align: center;
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .notif-item {
            display: block;
            padding: 10px 14px;
            border-bottom: 1px solid #f8fafc;
            transition: background .15s ease;
            cursor: pointer;
        }

        .notif-item:hover {
            background: #f8fafc;
        }

        .notif-item-unread {
            background: #f5f7ff;
        }

        .notif-item-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 2px;
        }

        .notif-item-pesan {
            font-size: 0.76rem;
            color: #64748b;
            line-height: 1.4;
            margin-bottom: 3px;
        }

        .notif-item-waktu {
            font-size: 0.68rem;
            color: #94a3b8;
        }
    </style>

    <script>
        (function () {
            const notifBtn = document.getElementById('notifBtn');
            const notifDropdown = document.getElementById('notifDropdown');
            const notifBadge = document.getElementById('notifBadge');
            const notifBody = notifDropdown.querySelector('.notif-dropdown-body');
            let notifMarkAll = document.getElementById('notifMarkAll');
            const notifHeader = notifDropdown.querySelector('.notif-dropdown-header');

            const notifSound = document.getElementById('notifSound');
            let lastNotifCount = <?= (int) $topbar_notif_count ?>;
            let audioUnlocked = false;

            // --- Bagian baru: deteksi notif yang masuk SEBELUM halaman ini dibuka ---
            // Disimpan per user_id, supaya kalau ganti akun di browser yang sama tetap akurat.
            const notifStorageKey = 'notif_last_seen_count_user_<?= (int) $topbar_user_id ?>';
            const storedRaw = localStorage.getItem(notifStorageKey);
            const storedCount = storedRaw === null ? lastNotifCount : parseInt(storedRaw, 10);

            // Kalau jumlah notif sekarang > jumlah terakhir yang "diketahui" user ini,
            // berarti ada notif baru yang masuk sejak terakhir dia buka website (termasuk saat logout).
            let pendingNotifSound = lastNotifCount > storedCount;

            // Update penanda terakhir yang diketahui, supaya tidak dianggap "baru" lagi di reload berikutnya.
            localStorage.setItem(notifStorageKey, lastNotifCount);

            function playNotifSound() {
                notifSound.currentTime = 0;
                notifSound.play().then(() => {
                    audioUnlocked = true;
                    pendingNotifSound = false;
                }).catch(() => {
                    // Browser masih memblokir autoplay (belum ada interaksi user).
                    // Biarkan pendingNotifSound tetap true, akan dicoba lagi saat user klik pertama kali.
                });
            }

            // Coba mainkan langsung saat halaman dibuka (berhasil kalau browser mengizinkan)
            if (pendingNotifSound) {
                playNotifSound();
            }

            document.addEventListener('click', function unlockAudio() {
                if (audioUnlocked) {
                    document.removeEventListener('click', unlockAudio);
                    return;
                }
                if (pendingNotifSound) {
                    // Klik pertama user: sekalian bunyikan notif yang tertunda tadi
                    playNotifSound();
                } else {
                    notifSound.play().then(() => {
                        notifSound.pause();
                        notifSound.currentTime = 0;
                        audioUnlocked = true;
                    }).catch(() => { });
                }
                document.removeEventListener('click', unlockAudio);
            }, { once: true });

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str ?? '';
                return div.innerHTML;
            }

            function bindNotifItemClick(item) {
                item.addEventListener('click', function () {
                    const id = item.dataset.id;
                    const sudahDibaca = item.dataset.dibaca === '1';
                    const url = item.dataset.url;

                    if (!sudahDibaca) {
                        fetch('<?= htmlspecialchars($topbar_base) ?>includes/topbar.php?ajax=mark_read&id=' + encodeURIComponent(id))
                            .then(res => res.json())
                            .then(json => {
                                if (json.success) {
                                    item.classList.remove('notif-item-unread');
                                    item.dataset.dibaca = '1';

                                    if (notifBadge) {
                                        let current = parseInt(notifBadge.textContent) || 0;
                                        current = Math.max(0, current - 1);
                                        if (current > 0) {
                                            notifBadge.textContent = current > 9 ? '9+' : current;
                                        } else {
                                            notifBadge.style.display = 'none';
                                        }
                                    }
                                }
                            })
                            .finally(() => {
                                if (url) window.location.href = url;
                            });
                    } else if (url) {
                        window.location.href = url;
                    }
                });
            }

            // Render ulang isi dropdown dari daftar notifikasi terbaru (dipakai saat polling)
            function renderNotifList(list) {
                if (!list || list.length === 0) {
                    notifBody.innerHTML = '<div class="notif-empty">Belum ada notifikasi.</div>';
                } else {
                    notifBody.innerHTML = list.map(function (n) {
                        return `
                    <div class="notif-item ${!n.sudah_dibaca ? 'notif-item-unread' : ''}"
                        data-id="${n.id}" data-dibaca="${n.sudah_dibaca ? '1' : '0'}"
                        data-url="${escapeHtml(n.url)}">
                        <div class="notif-item-title">${escapeHtml(n.judul)}</div>
                        <div class="notif-item-pesan">${escapeHtml(n.pesan)}</div>
                        <div class="notif-item-waktu">${escapeHtml(n.waktu)}</div>
                    </div>
                `;
                    }).join('');
                }

                notifBody.querySelectorAll('.notif-item').forEach(bindNotifItemClick);

                // Tombol "Tandai semua dibaca" ikut disesuaikan
                const adaUnread = list.some(n => !n.sudah_dibaca);
                if (notifMarkAll) {
                    notifMarkAll.style.display = adaUnread ? '' : 'none';
                }
            }

            function cekNotifBaru() {
                fetch('<?= htmlspecialchars($topbar_base) ?>includes/topbar.php?ajax=get_count')
                    .then(res => res.json())
                    .then(json => {
                        if (!json.success) return;

                        const adaNotifBaru = json.count > lastNotifCount;

                        renderNotifList(json.list);

                        if (notifBadge) {
                            if (json.count > 0) {
                                notifBadge.textContent = json.count > 9 ? '9+' : json.count;
                                notifBadge.style.display = '';
                            } else {
                                notifBadge.style.display = 'none';
                            }
                        }

                        if (adaNotifBaru) {
                            notifSound.currentTime = 0;
                            notifSound.play().catch(() => { });
                        }

                        lastNotifCount = json.count;
                        localStorage.setItem(notifStorageKey, json.count); // <-- tambahan ini
                    })
                    .catch(() => { });
            }

            setInterval(cekNotifBaru, 15000);

            if (!notifBtn) return;

            notifBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                notifDropdown.classList.toggle('show');
            });

            document.addEventListener('click', function (e) {
                if (!notifDropdown.contains(e.target) && e.target !== notifBtn) {
                    notifDropdown.classList.remove('show');
                }
            });

            // Bind item yang di-render server-side saat load awal
            notifDropdown.querySelectorAll('.notif-item').forEach(bindNotifItemClick);

            if (notifMarkAll) {
                notifMarkAll.addEventListener('click', function () {
                    fetch('<?= htmlspecialchars($topbar_base) ?>includes/topbar.php?ajax=mark_all_read')
                        .then(res => res.json())
                        .then(json => {
                            if (json.success) {
                                notifDropdown.querySelectorAll('.notif-item-unread').forEach(function (el) {
                                    el.classList.remove('notif-item-unread');
                                    el.dataset.dibaca = '1';
                                });
                                if (notifBadge) notifBadge.style.display = 'none';
                                notifMarkAll.style.display = 'none';
                            }
                        })
                        .catch(() => { });
                });
            }
        })();
    </script>