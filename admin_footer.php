<?php
/**
 * Shared admin footer for WebberSites AI Studio.
 * Optionally set $footerScripts = ['path.js'] before including to append scripts.
 */
?>
        </section>
    </div>
</div>
<?php if (!empty($footerScripts) && is_array($footerScripts)): ?>
    <?php foreach ($footerScripts as $scriptFile): ?>
        <script src="<?php echo htmlspecialchars($scriptFile); ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('mobileMenuToggle');
    if (toggle) {
        toggle.addEventListener('click', function() {
            document.body.classList.toggle('mobile-open');
        });
    }
});
</script>
</body>
</html>
