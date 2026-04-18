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
                    <?php ee(site_setting('site_tagline', 'Follow the trail. Receipts matter.')); ?>
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
                    <li><a href="/index.php?page=cases">Cases</a></li>
                    <li><a href="/index.php?page=series">Series</a></li>
                    <li><a href="/index.php?page=case-chat">Case Chat</a></li>
                    <li>
                        <a href="/index.php?page=case-chat" class="ptmd-footer-live d-inline-flex align-items-center gap-2">
                            Live
                            <span class="ptmd-live-dot" aria-hidden="true"></span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Social column -->
            <div class="col-6 col-lg-3">
                <h6 class="ptmd-footer-heading">
                    <i class="fa-solid fa-share-nodes me-1"></i>Follow Us</h6>
                <div class="d-flex flex-column gap-2 ptmd-footer-links">
                    <?php if (site_setting('social_youtube')): ?>
                        <a href="<?php ee(site_setting('social_youtube')); ?>" target="_blank" rel="noopener">
                            <i class="fa-brands fa-youtube me-2" style="color:var(--ptmd-red)"></i>YouTube
                        </a>
                    <?php endif; ?>
                    <?php if (site_setting('social_x')): ?>
                        <a href="<?php ee(site_setting('social_x')); ?>" target="_blank" rel="noopener">
                            <i class="fa-brands fa-x-twitter me-2" style="color:var(--ptmd-x-blue)"></i>X (Twitter)
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
                <h6 class="ptmd-footer-heading">Drop a Tip</h6>
                <p class="ptmd-muted small">
                    Have a lead? A receipt? A document that proves something?
                    The timeline never lies — show us yours.
                </p>
                <a href="mailto:<?php ee(site_setting('site_email', 'papertrailmd@gmail.com')); ?>" class="btn btn-ptmd-secondary btn-sm">
                    <i class="fa-solid fa-paper-plane me-1"></i>Drop a Tip
                </a>
            </div>

        </div>

        <hr class="ptmd-divider mt-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3">
            <p class="ptmd-muted small mb-0">
                &copy; <?php echo date('Y'); ?> <?php ee($siteName); ?>. All rights reserved.
            </p>
            <p class="ptmd-muted small mb-0">
                🧾 Receipts matter.
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

<!-- PTMD First-party Analytics (non-blocking) -->
<script>
(function () {
    'use strict';

    // Case ID is passed from the PHP context (null on non-case pages)
    var episodeId = <?php echo isset($current_case['id']) ? (int) $current_case['id'] : 'null'; ?>;

    /**
     * Send an event to the track_event API.
     * Uses sendBeacon when available so it fires reliably on page unload.
     */
    function sendEvent(type, extra) {
        var payload = { event_type: type };
        if (episodeId) { payload.episode_id = episodeId; }
        if (extra)     { payload.extra       = extra; }

        try {
            var body = JSON.stringify(payload);
            if (navigator.sendBeacon) {
                // Wrap in a Blob to send with application/json content-type
                navigator.sendBeacon(
                    '/api/track_event.php',
                    new Blob([body], { type: 'application/json' })
                );
            } else {
                fetch('/api/track_event.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: body,
                    keepalive: true,
                }).catch(function () { /* silent */ });
            }
        } catch (err) { /* silent */ }
    }

    // Page view — fires on every public page load
    sendEvent('page_view');

    // Social link clicks — tracks outbound social link engagement
    document.querySelectorAll(
        'a[href*="youtube.com"], a[href*="tiktok.com"], a[href*="instagram.com"], a[href*="x.com/"], a[href*="twitter.com"], a[href*="facebook.com"]'
    ).forEach(function (el) {
        el.addEventListener('click', function () {
            sendEvent('link_click', { href: el.href.substring(0, 100) });
        });
    });

    // HTML5 video tracking — for self-hosted videos rendered with id="ptmdPlayer"
    var video = document.getElementById('ptmdPlayer');
    if (video) {
        var playFired     = false;
        var completeFired = false;

        video.addEventListener('play', function () {
            if (!playFired) {
                playFired = true;
                sendEvent('video_play');
            }
        });

        video.addEventListener('timeupdate', function () {
            if (!completeFired && video.duration > 0 && (video.currentTime / video.duration) >= 0.9) {
                completeFired = true;
                sendEvent('video_complete');
            }
        });
    }
}());
</script>

</body>
</html>
