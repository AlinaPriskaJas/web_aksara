/* assets/js/script.js */

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
