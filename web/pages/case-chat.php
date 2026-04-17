<?php
/**
 * PTMD — Case Chat page (public)
 *
 * Live investigation feed backed by the chat_messages table.
 * Messages are fetched via /api/chat_messages.php and submitted via the same endpoint.
 */
?>

<div class="container py-4">

    <!-- ── Investigation header ─────────────────────────────────── -->
    <div class="ptmd-case-header" data-animate>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="ptmd-live-badge">
                <span class="live-dot"></span>LIVE
            </span>
            <span class="ptmd-badge-case-chat">Case Chat</span>
            <h1 class="case-title mb-0">Audience Dispatch</h1>
        </div>
        <div class="d-flex align-items-center gap-3 case-meta">
            <span class="ptmd-viewer-count">
                <i class="fa-solid fa-eye" style="color:var(--text-dim)"></i>
                <span class="viewer-num" id="viewerCount">—</span> watching
            </span>
        </div>
    </div>

    <!-- ── Stat row ──────────────────────────────────────────────── -->
    <div class="ptmd-stat-row mb-4" data-animate data-animate-delay="60">
        <div class="ptmd-stat-block ptmd-stat-block--approved">
            <div class="stat-block-label">Approved</div>
            <div class="stat-block-value" id="statApproved">—</div>
        </div>
        <div class="ptmd-stat-block ptmd-stat-block--flagged">
            <div class="stat-block-label">Flagged</div>
            <div class="stat-block-value" id="statFlagged">—</div>
        </div>
        <div class="ptmd-stat-block ptmd-stat-block--blocked">
            <div class="stat-block-label">Blocked</div>
            <div class="stat-block-value" id="statBlocked">—</div>
        </div>
    </div>

    <!-- ── 2-column investigation layout ────────────────────────── -->
    <div class="ptmd-investigation-layout" data-animate data-animate-delay="100">

        <!-- LEFT: Live feed ────────────────────────────────────── -->
        <div class="ptmd-feed-shell">

            <!-- Feed header -->
            <div class="ptmd-feed-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-bolt" style="color:var(--accent-yellow);font-size:13px"></i>
                    <span class="ptmd-label" style="color:var(--text-secondary)">Live Feed</span>
                </div>
                <span class="ptmd-label" style="color:var(--text-dim);font-size:10px" id="msgCount">Loading…</span>
            </div>

            <!-- Messages -->
            <div
                class="ptmd-feed-messages"
                id="caseChatMessages"
                data-endpoint="/api/chat_messages.php"
                role="log"
                aria-live="polite"
                aria-label="Case chat messages"
            >
                <div class="ptmd-muted small text-center py-5">
                    <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading messages…
                </div>
            </div>

            <!-- Input bar -->
            <div class="ptmd-feed-input-bar">
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
                                placeholder="Drop your case notes… 🔍🔥📄"
                                required
                            >
                        </div>
                        <div class="col-sm-2 d-grid">
                            <button class="btn btn-ptmd-red" type="submit" aria-label="Send message">
                                <i class="fa-solid fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                    <p class="small mt-2 mb-0" style="color:var(--text-dim)">
                        <i class="fa-solid fa-shield-halved me-1"></i>
                        Messages are moderated. Be factual. Be decent.
                    </p>
                </form>
            </div>

        </div>

        <!-- RIGHT: Controls panel ──────────────────────────────── -->
        <div class="ptmd-control-panel">

            <!-- Guidelines -->
            <div class="ptmd-control-card">
                <div class="ptmd-control-card-title">
                    <i class="fa-solid fa-file-lines" style="color:var(--accent-cyan)"></i>
                    Case Rules
                </div>
                <ul class="list-unstyled d-flex flex-column gap-2 mb-0" style="font-size:13px">
                    <li class="d-flex gap-2" style="color:var(--text-secondary)">
                        <i class="fa-solid fa-check mt-1" style="color:var(--status-approved);flex-shrink:0"></i>
                        Stay on topic — this is a case board
                    </li>
                    <li class="d-flex gap-2" style="color:var(--text-secondary)">
                        <i class="fa-solid fa-check mt-1" style="color:var(--status-approved);flex-shrink:0"></i>
                        Sources and questions welcome
                    </li>
                    <li class="d-flex gap-2" style="color:var(--text-secondary)">
                        <i class="fa-solid fa-check mt-1" style="color:var(--status-approved);flex-shrink:0"></i>
                        Satire is fine; harassment is not
                    </li>
                    <li class="d-flex gap-2" style="color:var(--text-muted)">
                        <i class="fa-solid fa-xmark mt-1" style="color:var(--status-blocked);flex-shrink:0"></i>
                        No personal attacks or doxxing
                    </li>
                    <li class="d-flex gap-2" style="color:var(--text-muted)">
                        <i class="fa-solid fa-xmark mt-1" style="color:var(--status-blocked);flex-shrink:0"></i>
                        Spam = immediate block
                    </li>
                </ul>
            </div>

            <!-- Feed settings -->
            <div class="ptmd-control-card">
                <div class="ptmd-control-card-title">
                    <i class="fa-solid fa-sliders" style="color:var(--accent-yellow)"></i>
                    Feed Settings
                </div>

                <div class="ptmd-toggle-row">
                    <span class="toggle-label">Auto-scroll</span>
                    <label class="ptmd-toggle">
                        <input type="checkbox" id="toggleAutoScroll" checked>
                        <span class="ptmd-toggle-track"></span>
                        <span class="ptmd-toggle-thumb"></span>
                    </label>
                </div>

                <div class="ptmd-toggle-row">
                    <span class="toggle-label">Show timestamps</span>
                    <label class="ptmd-toggle">
                        <input type="checkbox" id="toggleTimestamps" checked>
                        <span class="ptmd-toggle-track"></span>
                        <span class="ptmd-toggle-thumb"></span>
                    </label>
                </div>

                <div class="ptmd-toggle-row">
                    <span class="toggle-label">Compact view</span>
                    <label class="ptmd-toggle">
                        <input type="checkbox" id="toggleCompact">
                        <span class="ptmd-toggle-track"></span>
                        <span class="ptmd-toggle-thumb"></span>
                    </label>
                </div>
            </div>

            <!-- Status key -->
            <div class="ptmd-control-card">
                <div class="ptmd-control-card-title">
                    <i class="fa-solid fa-circle-half-stroke" style="color:var(--text-muted)"></i>
                    Status Key
                </div>
                <div class="d-flex flex-column gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <span class="ptmd-msg-card ptmd-msg-card--approved px-3 py-1 d-inline-block" style="font-size:11px;font-weight:700;letter-spacing:0.1em">APPROVED</span>
                        <span style="font-size:12px;color:var(--text-muted)">Visible to all</span>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="ptmd-msg-card ptmd-msg-card--flagged px-3 py-1 d-inline-block" style="font-size:11px;font-weight:700;letter-spacing:0.1em">FLAGGED</span>
                        <span style="font-size:12px;color:var(--text-muted)">Under review</span>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="ptmd-msg-card ptmd-msg-card--blocked px-3 py-1 d-inline-block" style="font-size:11px;font-weight:700;letter-spacing:0.1em">BLOCKED</span>
                        <span style="font-size:12px;color:var(--text-muted)">Removed</span>
                    </div>
                </div>
            </div>

            <!-- Auto-refresh notice -->
            <div class="ptmd-control-card" style="background:rgba(46,196,182,0.04);border-color:rgba(46,196,182,0.2)">
                <div class="d-flex align-items-start gap-3">
                    <i class="fa-solid fa-rotate" style="color:var(--accent-cyan);margin-top:2px;flex-shrink:0"></i>
                    <div>
                        <div class="ptmd-label mb-1" style="color:var(--accent-cyan)">Auto-Refreshes</div>
                        <p class="mb-0" style="font-size:12px;color:var(--text-muted)">
                            New messages appear automatically every 15 seconds. No page reload needed.
                        </p>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>
