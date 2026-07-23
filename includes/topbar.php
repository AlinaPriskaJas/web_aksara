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
                ':id'      => $notif_id,
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
$topbar_notif_list  = [];
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
        $topbar_notif_list  = [];
        $topbar_notif_count = 0;
    }
}

// Helper: ubah waktu jadi "X menit lalu" dsb, biar ringkas
function topbar_waktu_relatif(string $waktu): string
{
    $detik = time() - strtotime($waktu);
    if ($detik < 60) return 'Baru saja';
    if ($detik < 3600) return floor($detik / 60) . ' menit lalu';
    if ($detik < 86400) return floor($detik / 3600) . ' jam lalu';
    if ($detik < 172800) return 'Kemarin';
    return date('d M Y', strtotime($waktu));
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
                <span class="notification-dot" id="notifBadge" style="<?= $topbar_notif_count > 0 ? '' : 'display:none;' ?>"></span>
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
                                 data-id="<?= (int) $n['id'] ?>"
                                 data-dibaca="<?= $n['sudah_dibaca'] ? '1' : '0' ?>">
                                <div class="notif-item-title"><?= htmlspecialchars($n['judul']) ?></div>
                                <div class="notif-item-pesan"><?= htmlspecialchars($n['pesan']) ?></div>
                                <div class="notif-item-waktu"><?= topbar_waktu_relatif($n['waktu_kirim']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

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
    top: 6px;
    right: 6px;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #ef4444;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.25);
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
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    z-index: 1000;
    overflow: hidden;
}
.notif-dropdown.show { display: block; }

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
.notif-mark-all:hover { text-decoration: underline; }

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
.notif-item:hover { background: #f8fafc; }
.notif-item-unread { background: #f5f7ff; }
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
    const notifBtn      = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifBadge    = document.getElementById('notifBadge');
    const notifMarkAll  = document.getElementById('notifMarkAll');

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

    // Klik satu notifikasi: tandai dibaca via AJAX ke topbar.php sendiri, TIDAK pindah halaman
    notifDropdown.querySelectorAll('.notif-item').forEach(function (item) {
        item.addEventListener('click', function () {
            const id = item.dataset.id;
            const sudahDibaca = item.dataset.dibaca === '1';

            if (!sudahDibaca) {
                fetch('<?= htmlspecialchars($topbar_base) ?>includes/topbar.php?ajax=mark_read&id=' + encodeURIComponent(id))
                    .then(res => res.json())
                    .then(json => {
                        if (json.success) {
                            item.classList.remove('notif-item-unread');
                            item.dataset.dibaca = '1';

                            const masihAdaUnread = notifDropdown.querySelectorAll('.notif-item-unread').length > 0;
                            if (!masihAdaUnread) {
                                if (notifBadge) notifBadge.style.display = 'none';
                                if (notifMarkAll) notifMarkAll.style.display = 'none';
                            }
                        }
                    })
                    .catch(() => {});
            }
        });
    });

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
                .catch(() => {});
        });
    }
})();
</script>