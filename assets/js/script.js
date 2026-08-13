/* assets/js/script.js */

// ============================================================
// Global Page Loader
// Menampilkan overlay loading saat halaman dimuat & saat form
// (termasuk upload file ke Google Drive) sedang diproses, supaya
// user tidak melihat "layar putih" kosong tanpa indikator.
// ============================================================
(function () {
    var loader = document.getElementById('page-loader');
    if (!loader) return;

    function hideLoader() {
        loader.classList.add('is-hidden');
    }
    function showLoader(text) {
        var textEl = document.getElementById('page-loader-text');
        if (textEl && text) textEl.textContent = text;
        loader.classList.remove('is-hidden');
    }

    // Sembunyikan begitu HTML halaman selesai di-parse (bukan nunggu SEMUA
    // gambar/aset selesai — itu sebabnya dipakai DOMContentLoaded, bukan
    // 'load'. Kalau pakai 'load', loader akan nunggu foto/gambar paling
    // lambat di halaman itu selesai diunduh dulu baru hilang, sehingga
    // halaman yang banyak gambar (mis. daftar bukti absensi/sertifikat)
    // malah terasa lebih lama loading, padahal kontennya sudah siap).
    document.addEventListener('DOMContentLoaded', hideLoader);

    // Failsafe: jangan sampai loader "nyangkut" selamanya kalau ada
    // aset yang gagal/lambat dimuat.
    setTimeout(hideLoader, 8000);

    // Saat kembali lewat tombol back/forward browser (bfcache),
    // pastikan loader tidak ikut tersangkut dalam kondisi tampil.
    window.addEventListener('pageshow', function () {
        hideLoader();
    });

    // Tampilkan kembali loader begitu ada form yang disubmit (mis. form
    // upload sertifikat, surat, absensi, dsb), supaya jeda menunggu respons
    // server/Google Drive punya indikator visual, bukan layar kosong.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;

        if (form.hasAttribute('data-no-loader') || form.target === '_blank') return;
        if (e.defaultPrevented) return;

        var hasFileInput = !!form.querySelector('input[type="file"]');
        showLoader(hasFileInput ? 'Mengunggah file...' : 'Memproses...');

        // Cegah klik ganda tombol submit selama proses upload berlangsung.
        // PENTING: disable-nya ditunda 1 tick (setTimeout 0) supaya browser
        // sudah selesai menyusun & mengirim data form (termasuk nama/value
        // tombol submit yang diklik, mis. name="proses_approval") SEBELUM
        // tombolnya jadi disabled. Kalau di-disable langsung secara sync di
        // sini, browser akan menganggap tombol itu tidak "submit-able" lagi
        // dan MENGECUALIKAN name/value-nya dari data yang dikirim ke server
        // — akibatnya $_POST['proses_approval'] tidak pernah terkirim sama
        // sekali walau method-nya tetap POST.
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        setTimeout(function () {
            buttons.forEach(function (btn) {
                btn.disabled = true;
                btn.classList.add('is-loading');
            });
        }, 0);
    });
})();

document.addEventListener('DOMContentLoaded', function () {
    // 1. Sidebar Drawer Toggle for Mobile / Tablet Viewports
    const hamburgerBtn = document.getElementById('hamburger-btn');
    const sidebar = document.getElementById('sidebar');

    // Create and append overlay backdrop dynamically if not exists
    let overlay = document.getElementById('sidebar-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'sidebar-overlay';
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }

    if (hamburgerBtn && sidebar) {
        hamburgerBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });
    }

    // Close sidebar when clicking outside (on the overlay backdrop)
    if (overlay) {
        overlay.addEventListener('click', function () {
            if (sidebar.classList.contains('show')) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            }
        });
    }

    // Close sidebar if user presses Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        }
    });

    // 2. Dynamic Header Title and Breadcrumb Resolution
    // Detect active menu link in the sidebar
    const activeListItem = document.querySelector('.sidebar-menu li.active');
    if (activeListItem) {
        const activeLink = activeListItem.querySelector('a');
        if (activeLink) {
            // Clone active link to extract text without altering original elements
            const clonedLink = activeLink.cloneNode(true);

            // Remove any icons or inner icon tags
            const icons = clonedLink.querySelectorAll('i, svg, span.badge');
            icons.forEach(icon => icon.remove());

            // Extract the clean page title
            const activePageTitle = clonedLink.textContent.trim();

            // Update the topbar title
            const topbarTitle = document.getElementById('topbar-title');
            if (topbarTitle) {
                topbarTitle.textContent = activePageTitle;

                // Update document head title too for good practice
                document.title = activePageTitle + " - PT Aksara Riksa Perdana";
            }

            // Generate breadcrumb path
            const breadcrumbEl = document.getElementById('topbar-breadcrumb');
            const roleEl = document.querySelector('.sidebar-user-role');
            const userRole = roleEl ? roleEl.textContent.trim() : 'Dashboard';

            if (breadcrumbEl) {
                breadcrumbEl.innerHTML = `
                    <span class="text-muted">Home</span>
                    <span class="mx-1 text-muted">/</span>
                    <span class="text-muted">${userRole}</span>
                    <span class="mx-1 text-muted">/</span>
                    <span class="text-dark font-weight-bold">${activePageTitle}</span>
                `;
            }
        }
    }
});


// Smooth Scroll
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (href && href.length > 1) {
            try {
                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            } catch (err) {
                // Ignore invalid selectors like '#'
            }
        }
    });
});

// === Modal Global Functions ===
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden'; // prevent background scroll
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function closeModalOutside(event, modalId) {
    const modal = document.getElementById(modalId);
    // If click is exactly on the overlay (not its children)
    if (event.target === modal) {
        closeModal(modalId);
    }
}

// === Preview Bukti Foto Absensi ===
// Membuat modal preview foto secara otomatis (sekali saja) lalu menampilkan gambar yang diklik.
function tampilkanBuktiFoto(src) {
    let modal = document.getElementById('modalBuktiFotoAbsensi');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalBuktiFotoAbsensi';
        modal.className = 'arp-modal-overlay';
        modal.setAttribute('onclick', "closeModalOutside(event, 'modalBuktiFotoAbsensi')");
        modal.innerHTML = `
            <div class="arp-modal-box" style="max-width:480px;">
                <div class="arp-modal-header">
                    <h6 class="fw-bold mb-0">Bukti Foto Absensi</h6>
                    <button type="button" class="arp-modal-close" onclick="closeModal('modalBuktiFotoAbsensi')">&times;</button>
                </div>
                <div class="arp-modal-body text-center">
                    <img id="imgBuktiFotoAbsensi" src="" alt="Bukti Foto Absensi" class="arp-bukti-foto-preview">
                </div>
            </div>`;
        document.body.appendChild(modal);
    }
    document.getElementById('imgBuktiFotoAbsensi').src = src;
    openModal('modalBuktiFotoAbsensi');
}

/* =========================================================
   Table Search + Pagination Controller
   Used by card-box tables via the .table-toolbar search box
   and the .pagination-custom footer under each table.
   ========================================================= */
const tablePaginationState = {};

/**
 * Initialize pagination (and search-awareness) for a table.
 * Usage: initTablePagination('tabelJadwal', 10)
 */
function initTablePagination(tableId, rowsPerPage) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const allRows = Array.from(tbody.querySelectorAll('tr')).filter(function (row) {
        return !row.hasAttribute('data-search-empty');
    });

    tablePaginationState[tableId] = {
        rowsPerPage: rowsPerPage || 10,
        currentPage: 1,
        allRows: allRows
    };

    renderTablePage(tableId);
}

function getFilteredTableRows(tableId) {
    const state = tablePaginationState[tableId];
    if (!state) return [];

    const searchInput = document.querySelector('[data-table-search="' + tableId + '"]');
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';

    if (!query) return state.allRows;

    return state.allRows.filter(function (row) {
        return row.textContent.toLowerCase().indexOf(query) !== -1;
    });
}

/**
 * Called from the table-toolbar search input on keyup.
 * Usage: <input data-table-search="tabelJadwal" onkeyup="handleTableSearch('tabelJadwal')">
 */
function handleTableSearch(tableId) {
    const state = tablePaginationState[tableId];
    if (!state) return;
    state.currentPage = 1;
    renderTablePage(tableId);
}

function goToTablePage(tableId, page) {
    const state = tablePaginationState[tableId];
    if (!state) return;

    const totalPages = Math.max(1, Math.ceil(getFilteredTableRows(tableId).length / state.rowsPerPage));
    if (page < 1 || page > totalPages) return;

    state.currentPage = page;
    renderTablePage(tableId);
}

function renderTablePage(tableId) {
    const state = tablePaginationState[tableId];
    const table = document.getElementById(tableId);
    if (!state || !table) return;

    const tbody = table.querySelector('tbody');
    const filteredRows = getFilteredTableRows(tableId);
    const totalRows = filteredRows.length;
    const totalPages = Math.max(1, Math.ceil(totalRows / state.rowsPerPage));
    if (state.currentPage > totalPages) state.currentPage = totalPages;

    // Hide every real row first
    state.allRows.forEach(function (row) {
        row.style.display = 'none';
    });

    // Show only the rows for the current page
    const start = (state.currentPage - 1) * state.rowsPerPage;
    const pageRows = filteredRows.slice(start, start + state.rowsPerPage);
    pageRows.forEach(function (row) {
        row.style.display = '';
    });

    // "No data / not found" placeholder row
    let emptyRow = tbody.querySelector('tr[data-search-empty="true"]');
    if (totalRows === 0) {
        if (!emptyRow) {
            const colCount = table.querySelectorAll('thead th').length || 1;
            emptyRow = document.createElement('tr');
            emptyRow.setAttribute('data-search-empty', 'true');
            emptyRow.innerHTML = '<td colspan="' + colCount + '" class="text-center py-4 text-muted">Data tidak ditemukan.</td>';
            tbody.appendChild(emptyRow);
        }
        emptyRow.style.display = '';
    } else if (emptyRow) {
        emptyRow.style.display = 'none';
    }

    renderTablePaginationControls(tableId, totalRows, totalPages);
}

// Menghasilkan jendela nomor halaman yang bergeser mengikuti halaman aktif,
// selalu menampilkan maksimal 5 angka (tanpa "..."). Contoh: kalau sedang
// di halaman 7 dari 20 halaman -> tampil [5, 6, 7, 8, 9], lalu ikut geser
// begitu pindah halaman.
function getPaginationRange(current, total, windowSize) {
    windowSize = Math.min(windowSize || 5, total);
    let start = Math.max(1, current - Math.floor(windowSize / 2));
    let end = start + windowSize - 1;

    if (end > total) {
        end = total;
        start = Math.max(1, end - windowSize + 1);
    }

    const range = [];
    for (let i = start; i <= end; i++) {
        range.push(i);
    }
    return range;
}

function renderTablePaginationControls(tableId, totalRows, totalPages) {
    const state = tablePaginationState[tableId];
    const container = document.getElementById('pagination-' + tableId);
    if (!state || !container) return;

    const start = totalRows === 0 ? 0 : (state.currentPage - 1) * state.rowsPerPage + 1;
    const end = Math.min(state.currentPage * state.rowsPerPage, totalRows);

    let html = '<div class="pagination-info text-muted" style="font-size:0.875rem;">Menampilkan ' + start + '-' + end + ' dari ' + totalRows + ' data</div>';

    html += '<ul class="pagination-pages">';

    const prevDisabled = state.currentPage === 1;
    html += '<li class="pagination-item' + (prevDisabled ? ' disabled' : '') + '">' +
        '<a href="javascript:void(0)"' + (prevDisabled ? '' : ' onclick="goToTablePage(\'' + tableId + '\', ' + (state.currentPage - 1) + ')"') + '>' +
        '<i class="bi bi-chevron-left"></i></a></li>';

    const pages = getPaginationRange(state.currentPage, totalPages);

    pages.forEach(function (p) {
        if (p === '...') {
            html += '<li class="pagination-item disabled"><span>&hellip;</span></li>';
            return;
        }
        const isActive = p === state.currentPage;
        html += '<li class="pagination-item' + (isActive ? ' active' : '') + '">' +
            '<span style="cursor:pointer;" onclick="goToTablePage(\'' + tableId + '\', ' + p + ')">' + p + '</span></li>';
    });

    const nextDisabled = state.currentPage === totalPages;
    html += '<li class="pagination-item' + (nextDisabled ? ' disabled' : '') + '">' +
        '<a href="javascript:void(0)"' + (nextDisabled ? '' : ' onclick="goToTablePage(\'' + tableId + '\', ' + (state.currentPage + 1) + ')"') + '>' +
        '<i class="bi bi-chevron-right"></i></a></li>';

    html += '</ul>';

    container.innerHTML = html;
}


function switchTab(targetId, btnEl) {
    if (!btnEl) return;
    const scope = btnEl.closest('.arp-tab-group') || document;

    scope.querySelectorAll('.arp-tab-panel').forEach(function (panel) {
        panel.style.display = (panel.id === targetId) ? '' : 'none';
    });
    scope.querySelectorAll('.arp-tab-btn').forEach(function (btn) {
        btn.classList.remove('active');
    });
    btnEl.classList.add('active');
}
window.switchTab = switchTab;

// Navbar Shadow Saat Scroll
window.addEventListener("scroll", function () {
    const navbar = document.querySelector(".navbar");
    if (navbar) {
        if (window.scrollY > 20) {
            navbar.style.boxShadow = "0 5px 20px rgba(0,0,0,.12)";
        } else {
            navbar.style.boxShadow = "0 3px 10px rgba(0,0,0,.08)";
        }
    }
});


/*=========================================
=            SLIDER PREVIEW HERO
=========================================*/

(function () {

    const slides = document.querySelectorAll(".preview-slide");

    if (slides.length <= 1) return;

    let current = 0;

    function showSlide(index) {

        slides[current].classList.remove("active");

        // restart animasi zoom
        slides[index].style.animation = "none";
        void slides[index].offsetWidth;
        slides[index].style.animation = "";

        current = index;

        slides[current].classList.add("active");

    }

    setInterval(function () {

        let next = current + 1;

        if (next >= slides.length) {
            next = 0;
        }

        showSlide(next);

    }, 5000);

})();