<?php
// includes/footer.php
?>
        <!-- Footer Section -->
        <footer id="footer">
            <span>&copy; <?php echo date('Y'); ?> PT Aksara Riksa Perdana. All rights reserved.</span>
        </footer>
    </div> <!-- Close #main-wrapper -->
</div> <!-- Close #app-layout -->

<!-- Bootstrap 5 Bundle JS (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Main App JavaScript -->
<script src="<?php echo $base_url; ?>assets/js/script.js"></script>

<!-- Prevent "Confirm Form Resubmission" dialog on refresh after a POST -->
<!-- Replaces the current history entry (created by the POST) with a plain GET
     entry for the same URL, so pressing F5/refresh re-fetches the page via GET
     instead of resubmitting the form data (which was causing duplicated data
     e.g. uploads, tambah, edit records being saved twice). -->
<script>
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
</script>
</body>
</html>
