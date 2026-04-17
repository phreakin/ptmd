<?php
/**
 * PTMD — Case Chat page (public)
 *
 * Live-ish audience feed backed by the chat_messages table.
 * Messages are fetched via /api/chat_messages.php and submitted via the same endpoint.
 */
?>

<section class="container py-5">

    <div class="mb-5" data-animate>
        <span class="ptmd-badge-live mb-3 d-inline-block">
            <span class="ptmd-live-dot me-1"></span>CASE CHAT
        </span>
        <h1 class="mb-2">Case Chat</h1>
        <p class="ptmd-hero-sub">
            The live audience dispatch feed. Drop your case notes, receipts, and reactions. 🧾
        </p>
    </div>

    <div class="row g-5">

        <div class="col-lg-8" data-animate>

            <!-- Chat shell -->
            <div class="ptmd-chat-shell">
                <div
                    class="ptmd-chat-messages"
                    id="caseChatMessages"
                    data-endpoint="/api/chat_messages.php"
                    role="log"
                    aria-live="polite"
                    aria-label="Case chat messages"
                >
                    <div class="ptmd-muted small text-center py-4">
                        <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading messages…
                    </div>
                </div>

                <!-- Input bar -->
                <div class="ptmd-chat-input-bar">
                    <form id="caseChatForm" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                        <div class="row g-2 align-items-center">
                            <div class="col-sm-3">
                                <input
                                    class="form-control"
                                    name="username"
                                    maxlength="50"
                                    placeholder="Your alias"
                                    required
                                >
                            </div>
                            <div class="col-sm-7">
                                <input
                                    class="form-control"
                                    name="message"
                                    maxlength="500"
                                    placeholder="Drop your case notes… emoji welcome 🔍🔥📄"
                                    required
                                >
                            </div>
                            <div class="col-sm-2 d-grid">
                                <button class="btn btn-ptmd-primary" type="submit" aria-label="Send message">
                                    <i class="fa-solid fa-paper-plane me-1"></i>Send
                                </button>
                            </div>
                        </div>
                        <p class="ptmd-muted small mt-2 mb-0">
                            <i class="fa-solid fa-shield-halved me-1"></i>
                            Moderated. Be factual, be funny, drop receipts. 🧾
                        </p>
                    </form>
                </div>
            </div>

        </div>

        <!-- Sidebar -->
        <div class="col-lg-4" data-animate data-animate-delay="120">
            <div class="ptmd-panel p-lg mb-4">
                <h2 class="h6 mb-3 ptmd-text-teal">
                    <i class="fa-solid fa-circle-info me-2"></i>Chat Guidelines
                </h2>
                <ul class="list-unstyled d-flex flex-column gap-2 ptmd-text-muted small mb-0">
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>Stay on topic — this is a case board</li>
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>Sources and questions welcome</li>
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>Satire is fine; harassment is not</li>
                    <li><i class="fa-solid fa-times ptmd-text-red me-2"></i>No personal attacks or doxxing</li>
                    <li><i class="fa-solid fa-times ptmd-text-red me-2"></i>Spam = immediate block</li>
                </ul>
            </div>
            <div class="ptmd-panel p-lg">
                <h2 class="h6 mb-3 ptmd-text-yellow">
                    <i class="fa-solid fa-bolt me-2"></i>Auto-refreshes
                </h2>
                <p class="ptmd-muted small mb-0">
                    New messages appear automatically every 15 seconds.
                    No page reload needed.
                </p>
            </div>
        </div>

    </div>

</section>
