<?php
/**
 * PTMD — Public site footer partial
 */
$siteName = site_setting('site_name', 'Paper Trail MD');
?>
</main><!-- /.ptmd-main -->

<footer class="ptmd-footer mt-auto">
    <div class="container py-5">
        <div class="row g-4 align-items-start">

            <!-- Brand column -->
            <div class="col-lg-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <img
                        src="/assets/brand/logos/ptmd_lockup.png"
                        alt="<?php ee($siteName); ?>"
                        class="ptmd-footer-logo"
                        onerror="this.style.display='none'"
                    >
                </div>
                <p class="ptmd-muted small">
                    <?php ee(site_setting('site_tagline', 'Investigative. Sharp. Cinematic.')); ?>
                </p>
                <p class="ptmd-muted small mb-0">
                    <a href="mailto:<?php ee(site_setting('site_email', 'papertrailmd@gmail.com')); ?>">
                        <?php ee(site_setting('site_email', 'papertrailmd@gmail.com')); ?>
                    </a>
                </p>
            </div>

            <!-- Navigation column -->
            <div class="col-6 col-lg-2">
                <h6 class="ptmd-footer-heading">Site</h6>
                <ul class="list-unstyled ptmd-footer-links">
                    <li><a href="/index.php">Home</a></li>
                    <li><a href="/index.php?page=series">Series</a></li>
                    <li><a href="/index.php?page=cases">Cases</a></li>
                    <li><a href="/index.php?page=case-chat">Case Chat</a></li>
                    <li><a href="/index.php?page=contact">Contact</a></li>
                </ul>
            </div>

            <!-- Social column -->
            <div class="col-6 col-lg-3">
                <h6 class="ptmd-footer-heading">Follow</h6>
                <div class="d-flex flex-column gap-2 ptmd-footer-links">
                    <?php if (site_setting('social_youtube')): ?>
                        <a href="<?php ee(site_setting('social_youtube')); ?>" target="_blank" rel="noopener">
                            <i class="fa-brands fa-youtube me-2" style="color:var(--ptmd-red)"></i>YouTube
                        </a>
                    <?php endif; ?>
                    <?php if (site_setting('social_x')): ?>
                        <a href="<?php ee(site_setting('social_x')); ?>" target="_blank" rel="noopener">
                            <i class="fa-brands fa-x-twitter me-2"></i>X / Twitter
                        </a>
                    <?php endif; ?>
                    <?php if (site_setting('social_instagram')): ?>
                        <a href="<?php ee(site_setting('social_instagram')); ?>" target="_blank" rel="noopener">
                            <i class="fa-brands fa-instagram me-2" style="color:var(--ptmd-pink)"></i>Instagram
                        </a>
                    <?php endif; ?>
                    <?php if (site_setting('social_tiktok')): ?>
                        <a href="<?php ee(site_setting('social_tiktok')); ?>" target="_blank" rel="noopener">
                            <i class="fa-brands fa-tiktok me-2"></i>TikTok
                        </a>
                    <?php endif; ?>
                    <?php if (site_setting('social_facebook')): ?>
                        <a href="<?php ee(site_setting('social_facebook')); ?>" target="_blank" rel="noopener">
                            <i class="fa-brands fa-facebook me-2" style="color:var(--ptmd-blue)"></i>Facebook
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CTA column -->
            <div class="col-lg-3">
                <h6 class="ptmd-footer-heading">Pitch a Story</h6>
                <p class="ptmd-muted small">
                    Have a lead? A tip? A document that proves something?
                    We want to know.
                </p>
                <a href="/index.php?page=contact" class="btn btn-ptmd-secondary btn-sm">
                    <i class="fa-solid fa-paper-plane me-1"></i>Submit a Tip
                </a>
            </div>

        </div>

        <hr class="ptmd-divider mt-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3">
            <p class="ptmd-muted small mb-0">
                &copy; <?php echo date('Y'); ?> <?php ee($siteName); ?>. All rights reserved.
            </p>
            <p class="ptmd-muted small mb-0">
                Built with intent.
            </p>
        </div>
    </div>
</footer>

</div><!-- /.ptmd-shell -->

<!-- JS Dependencies (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/jquery@latest/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@latest/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tippy.js@latest/dist/tippy-bundle.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/clipboard@latest/dist/clipboard.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@latest/dist/sweetalert2.all.min.js"></script>

<!-- PTMD App JS -->
<script src="/assets/js/app.js"></script>

</body>
</html>
