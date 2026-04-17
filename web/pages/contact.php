<?php
/**
 * PTMD — Contact page
 */
?>
<section class="container py-5">

    <div class="mb-5" data-animate>
        <span class="ptmd-badge-teal mb-3 d-inline-block">
            <i class="fa-solid fa-envelope me-1"></i> Contact
        </span>
        <h1 class="mb-2">Contact + Pitch a Story</h1>
        <p class="ptmd-hero-sub">
            Have a lead? A tip? A document that proves something? We want to know.
        </p>
    </div>

    <div class="row g-5">

        <!-- Contact form -->
        <div class="col-lg-7" data-animate>
            <div class="ptmd-panel p-xl">
                <h2 class="h5 mb-4">Send a Tip or Pitch</h2>
                <form method="post" action="/api/contact.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                    <div class="mb-3">
                        <label class="form-label" for="contact_name">Name</label>
                        <input
                            class="form-control"
                            id="contact_name"
                            name="name"
                            placeholder="Your name or alias"
                            required
                        >
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="contact_email">Email</label>
                        <input
                            class="form-control"
                            id="contact_email"
                            type="email"
                            name="email"
                            placeholder="you@example.com"
                            required
                        >
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="contact_subject">Subject</label>
                        <select class="form-select" id="contact_subject" name="subject">
                            <option value="story_pitch">Story Pitch</option>
                            <option value="tip">Tip / Leak</option>
                            <option value="document">Document Submission</option>
                            <option value="general">General Inquiry</option>
                            <option value="collaboration">Collaboration</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="contact_message">Message</label>
                        <textarea
                            class="form-control"
                            id="contact_message"
                            name="message"
                            rows="6"
                            placeholder="Tell us the story. The more receipts you have, the better."
                            required
                        ></textarea>
                    </div>
                    <button class="btn btn-ptmd-primary" type="submit">
                        <i class="fa-solid fa-paper-plane me-2"></i>Send Pitch
                    </button>
                </form>
            </div>
        </div>

        <!-- Contact info -->
        <div class="col-lg-5" data-animate data-animate-delay="120">
            <div class="ptmd-panel p-xl mb-4">
                <h2 class="h6 mb-3 ptmd-text-teal">
                    <i class="fa-solid fa-envelope me-2"></i>Direct Email
                </h2>
                <p class="ptmd-text-muted mb-1 small">
                    For sensitive tips, use encrypted email when possible.
                </p>
                <a href="mailto:<?php ee(site_setting('site_email', 'papertrailmd@gmail.com')); ?>" class="fw-600">
                    <?php ee(site_setting('site_email', 'papertrailmd@gmail.com')); ?>
                </a>
            </div>

            <div class="ptmd-panel p-xl mb-4">
                <h2 class="h6 mb-3 ptmd-text-yellow">
                    <i class="fa-solid fa-shield-halved me-2"></i>Source Protection
                </h2>
                <p class="ptmd-text-muted small mb-0">
                    We take source protection seriously. If your tip requires confidentiality, say so explicitly
                    and we will discuss secure transfer options before proceeding.
                </p>
            </div>

            <div class="ptmd-panel p-xl">
                <h2 class="h6 mb-3">
                    <i class="fa-brands fa-x-twitter me-2"></i>Social DMs
                </h2>
                <div class="d-flex flex-column gap-2">
                    <?php if (site_setting('social_x')): ?>
                        <a href="<?php ee(site_setting('social_x')); ?>" target="_blank" rel="noopener" class="ptmd-text-muted small">
                            X / Twitter
                        </a>
                    <?php endif; ?>
                    <?php if (site_setting('social_instagram')): ?>
                        <a href="<?php ee(site_setting('social_instagram')); ?>" target="_blank" rel="noopener" class="ptmd-text-muted small">
                            Instagram
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

</section>
