<?php
/**
 * PTMD — Public site footer partial
 */
$siteName = site_setting('site_name', 'Paper Trail MD');
?>
</main><!-- /.ptmd-main -->

<<footer class="ptmd-footer">
    <div class="ptmd-footer-inner">

        <!-- LEFT SIDE -->
        <div class="footer-left">
            <span class="footer-brand"><i class="fas fa-trail"></i> {{ $siteName }}</span>
            <span class="footer-divider">•</span>
            <span class="footer-tagline"><i class="fas fa-code"></i> Evidence‑Driven Advocacy</span>
        </div>

        <!-- RIGHT SIDE -->
        <div class="footer-right">
            <a href="/privacy" class="footer-link shimmer"><i class="fas fa-user-shield"></i> Privacy</a>
            <a href="/terms" class="footer-link shimmer"><i class="fas fa-gavel"></i> Terms</a>
            <a href="/contact" class="footer-link shimmer"><i class="fas fa-envelope"></i> Contact</a>

            <!-- SYSTEM STATUS -->
            <span class="footer-status">
        <span class="status-dot status-ok"></span>
        <span class="status-label"><i class="fas fa-circle"></i> Systems Operational</span>
      </span>

            <!-- VERSION -->
            <span class="footer-version">
        v{{ config('app.version', '1.0.0') }}
      </span>
        </div>

    </div>
</footer>
</div>

<!-- JS Dependencies (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/jquery@latest/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@latest/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@latest/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tippy.js@latest/dist/tippy-bundle.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/clipboard@latest/dist/clipboard.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@latest/dist/sweetalert2.all.min.js"></script>
<!-- PTMD App JS -->
<script src="/assets/js/app.js"></script>
</body>
</html>
