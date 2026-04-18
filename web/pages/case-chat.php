<?php
/**
 * PTMD - Case Chat page (public)
 *
 * Live audience feed backed by chat_messages, chat_rooms, and chat_users.
 * Messages are fetched/submitted via /api/chat_messages.php.
 * SSE streaming via /api/chat_sse.php.
 */
require_once __DIR__ . '/../inc/chat_auth.php';

$chatUser = current_chat_user();
$isMod = $chatUser && is_chat_moderator();
$roomSlug = trim(strip_tags((string) ($_GET['room'] ?? 'case-chat')));
?>

<section class="container py-5">

    <div class="mb-5" data-animate>
        <span class="ptmd-badge-live mb-3 d-inline-block">
            <span class="ptmd-live-dot me-1"></span>CASE CHAT
        </span>
        <h1 class="mb-2">Case Chat</h1>
        <p class="ptmd-hero-sub">
            The live audience dispatch feed. Drop your case notes, receipts, and reactions.
        </p>
    </div>

    <div class="ptmd-chat-auth-bar mb-4" data-animate>
        <?php if ($chatUser): ?>
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div class="d-flex align-items-center gap-2">
                    <div class="ptmd-chat-avatar" style="--avatar-color:<?php ee($chatUser['avatar_color']); ?>">
                        <?php echo e(strtoupper(substr($chatUser['display_name'], 0, 1))); ?>
                    </div>
                    <div>
                        <span class="fw-600 small"><?php ee($chatUser['display_name']); ?></span>
                        <?php if ($chatUser['role'] !== 'registered'): ?>
                            <span class="ptmd-chat-role-badge ptmd-chat-role-badge--<?php ee(str_replace('_', '-', $chatUser['role'])); ?> ms-2">
                                <?php ee(ucfirst(str_replace('_', ' ', $chatUser['role']))); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="/api/chat_logout.php" class="btn btn-ptmd-ghost btn-sm">
                    <i class="fa-solid fa-right-from-bracket me-1"></i>Sign Out
                </a>
            </div>
        <?php else: ?>
            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <p class="ptmd-muted small mb-0">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    <a href="<?php ee(route_chat_login()); ?>" class="ptmd-text-teal">Sign in</a> or
                    <a href="<?php ee(route_register()); ?>" class="ptmd-text-teal">register</a>
                    for reactions, replies, and highlights.
                </p>
                <div class="d-flex gap-2">
                    <a href="<?php ee(route_chat_login()); ?>" class="btn btn-ptmd-outline btn-sm">Sign In</a>
                    <a href="<?php ee(route_register()); ?>" class="btn btn-ptmd-teal btn-sm">Register</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-5">
        <div class="col-lg-8" data-animate>

            <div class="ptmd-chat-shell"
                 id="caseChatShell"
                 data-room="<?php ee($roomSlug); ?>"
                 data-endpoint="/api/chat_messages.php"
                 data-react-endpoint="/api/chat_react.php"
                 data-moderate-endpoint="/api/chat_moderate.php"
                 data-sse-endpoint="/api/chat_sse.php"
                 data-trivia-endpoint="/api/chat_trivia.php"
                 data-donate-endpoint="/api/chat_donate.php"
                 data-is-mod="<?php echo $isMod ? '1' : '0'; ?>"
                 data-user-id="<?php echo $chatUser ? (int) $chatUser['id'] : '0'; ?>"
                 data-user-role="<?php ee($chatUser['role'] ?? 'guest'); ?>">

                <div class="ptmd-chat-pinned d-none" id="caseChatPinned" aria-label="Pinned messages"></div>

                <div class="ptmd-chat-messages"
                     id="caseChatMessages"
                     role="log"
                     aria-live="polite"
                     aria-label="Case chat messages">
                    <div class="ptmd-muted small text-center py-4">
                        <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading messages...
                    </div>
                </div>

                <div class="ptmd-chat-input-bar" id="caseChatInputBar">

                    <?php if ($chatUser): ?>
                        <div id="caseChatReplyCtx" class="ptmd-chat-reply-ctx d-none">
                            <i class="fa-solid fa-reply me-1 ptmd-text-teal"></i>
                            <span id="caseChatReplyLabel" class="flex-grow-1"></span>
                            <button type="button" id="caseChatReplyClear"
                                    class="btn btn-ptmd-ghost btn-sm py-0 px-1" aria-label="Cancel reply">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <form id="caseChatForm" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="room" value="<?php ee($roomSlug); ?>">
                            <input type="hidden" name="parent_id" id="caseChatParentId" value="">

                            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                <button type="button" id="caseChatHighlightToggle"
                                        class="btn btn-ptmd-ghost btn-sm"
                                        data-tippy-content="Highlight your message (Super Chat)">
                                    <i class="fa-solid fa-star me-1"></i>Highlight
                                </button>
                                <div id="caseChatHighlightOpts" class="d-none d-flex gap-2 align-items-center">
                                    <label class="ptmd-muted small mb-0">Color:</label>
                                    <input type="color" name="highlight_color" id="caseChatHighlightColor"
                                           value="#FFD60A"
                                           style="width:36px;height:28px;padding:2px;border-radius:4px;border:1px solid var(--ptmd-border)">
                                </div>
                                <div id="caseChatSlowMode" class="ptmd-chat-slow-mode d-none ms-auto" aria-live="polite">
                                    <i class="fa-solid fa-clock me-1"></i>Wait <span id="caseChatSlowCount">0</span>s
                                </div>
                            </div>

                            <div class="d-flex gap-2 align-items-center">
                                <input class="form-control flex-grow-1"
                                       name="message" id="caseChatMessageInput"
                                       maxlength="500"
                                       placeholder="Drop your case notes... emoji welcome"
                                       required>
                                <button type="button" id="caseChatEmojiBtn"
                                        class="btn btn-ptmd-ghost btn-sm flex-shrink-0"
                                        aria-label="Open emoji picker"
                                        data-tippy-content="Emoji picker">🙂</button>
                                <button class="btn btn-ptmd-primary btn-sm flex-shrink-0"
                                        type="submit" id="caseChatSendBtn" aria-label="Send message">
                                    <i class="fa-solid fa-paper-plane me-1"></i>Send
                                </button>
                            </div>

                            <p class="ptmd-muted small mt-2 mb-0">
                                <i class="fa-solid fa-shield-halved me-1"></i>
                                Posting as <strong><?php ee($chatUser['display_name']); ?></strong>.
                                Moderated. Be factual, be funny, drop receipts.
                            </p>
                        </form>

                        <div id="caseChatEmojiPicker" class="ptmd-emoji-picker d-none" role="dialog" aria-label="Emoji picker">
                            <div class="ptmd-emoji-tabs" id="caseChatEmojiTabs"></div>
                            <div class="ptmd-emoji-grid" id="caseChatEmojiGrid"></div>
                        </div>

                    <?php else: ?>
                        <form id="caseChatForm" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                            <input type="hidden" name="room" value="<?php ee($roomSlug); ?>">
                            <div class="row g-2 align-items-center">
                                <div class="col-sm-3">
                                    <input class="form-control" name="username" maxlength="50"
                                           placeholder="Your alias" required>
                                </div>
                                <div class="col-sm-7">
                                    <input class="form-control" name="message" maxlength="500"
                                           placeholder="Drop your case notes... emoji welcome" required>
                                </div>
                                <div class="col-sm-2 d-grid">
                                    <button class="btn btn-ptmd-primary" type="submit" aria-label="Send message">
                                        <i class="fa-solid fa-paper-plane me-1"></i>Send
                                    </button>
                                </div>
                            </div>
                            <p class="ptmd-muted small mt-2 mb-0">
                                <i class="fa-solid fa-shield-halved me-1"></i>
                                Messages are moderated. Be factual, be funny, be decent.
                                <a href="<?php ee(route_chat_login()); ?>" class="ptmd-text-teal">Sign in</a>
                                for reactions and replies.
                            </p>
                        </form>
                    <?php endif; ?>

                </div>
            </div>

        </div>

        <div class="col-lg-4" data-animate data-animate-delay="120">

            <div id="triviaWidget" class="ptmd-trivia-widget mb-4 d-none"></div>

            <div id="donationPanel" class="ptmd-donation-panel mb-4 d-none"></div>

            <div class="ptmd-panel p-lg mb-4">
                <h2 class="h6 mb-3 ptmd-text-teal">
                    <i class="fa-solid fa-circle-info me-2"></i>Chat Guidelines
                </h2>
                <ul class="list-unstyled d-flex flex-column gap-2 ptmd-text-muted small mb-0">
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>Stay on topic - this is a case board</li>
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>Sources and questions welcome</li>
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>Satire is fine; harassment is not</li>
                    <li><i class="fa-solid fa-times" style="color:var(--ptmd-error)"></i><span class="me-2"></span>No personal attacks or doxxing</li>
                    <li><i class="fa-solid fa-times" style="color:var(--ptmd-error)"></i><span class="me-2"></span>Spam = immediate block</li>
                </ul>
            </div>
            <div class="ptmd-panel p-lg">
                <h2 class="h6 mb-3 ptmd-text-yellow">
                    <i class="fa-solid fa-bolt me-2"></i>Auto-refreshes
                </h2>
                <p class="ptmd-muted small mb-0">
                    New messages appear automatically via live streaming.
                    No page reload needed.
                </p>
            </div>
        </div>

    </div>
</section>
