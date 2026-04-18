<?php
/**
 * PTMD Admin — Chat Rooms
 *
 * Create, edit, and archive chat rooms.  Each room has a unique slug used
 * in the public URL (/index.php?page=case-chat&room=<slug>) and can be
 * linked to a specific case.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$pageTitle      = 'Chat Rooms | PTMD Admin';
$activePage     = 'chat-rooms';
$pageHeading    = 'Chat Rooms';
$pageSubheading = 'Create and manage rooms for Case Chat.';

$pdo = get_db();

// ── POST actions ───────────────────────────────────────────────────────────────
if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/chat-rooms.php', 'Invalid CSRF token.', 'danger');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    // ── Create / update room ────────────────────────────────────────────────
    if (in_array($action, ['create', 'update'], true)) {
        $id              = (int)  ($_POST['id']              ?? 0);
        $name            = trim((string) ($_POST['name']            ?? ''));
        $slug            = trim((string) ($_POST['slug']            ?? ''));
        $description     = trim((string) ($_POST['description']     ?? ''));
        $caseId          = (int)  ($_POST['case_id']          ?? 0) ?: null;
        $isLive          = isset($_POST['is_live'])          ? 1 : 0;
        $slowMode        = max(0, (int) ($_POST['slow_mode_seconds'] ?? 0));
        $membersOnly     = isset($_POST['members_only'])     ? 1 : 0;
        $reactionPolicy  = trim((string) ($_POST['reaction_policy'] ?? 'all'));
        $triviaEnabled   = isset($_POST['trivia_enabled'])   ? 1 : 0;
        $donationsEnabled = isset($_POST['donations_enabled']) ? 1 : 0;

        $validPolicies = ['all', 'registered', 'disabled'];
        if (!in_array($reactionPolicy, $validPolicies, true)) $reactionPolicy = 'all';

        if ($name === '' || $slug === '') {
            set_flash('Name and slug are required.', 'danger');
            redirect('/admin/chat-rooms.php');
        }

        // Normalise slug
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));

        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO chat_rooms (slug, name, description, case_id, is_live, slow_mode_seconds, members_only, reaction_policy, trivia_enabled, donations_enabled, is_archived, created_at, updated_at)
                 VALUES (:slug, :name, :desc, :case_id, :is_live, :slow, :mo, :rp, :te, :de, 0, NOW(), NOW())'
            );
            $stmt->execute([
                'slug'    => $slug,
                'name'    => $name,
                'desc'    => $description ?: null,
                'case_id' => $caseId,
                'is_live' => $isLive,
                'slow'    => $slowMode,
                'mo'      => $membersOnly,
                'rp'      => $reactionPolicy,
                'te'      => $triviaEnabled,
                'de'      => $donationsEnabled,
            ]);
            redirect('/admin/chat-rooms.php', 'Room created.', 'success');
        } else {
            $stmt = $pdo->prepare(
                'UPDATE chat_rooms SET slug=:slug, name=:name, description=:desc, case_id=:case_id,
                 is_live=:is_live, slow_mode_seconds=:slow, members_only=:mo,
                 reaction_policy=:rp, trivia_enabled=:te, donations_enabled=:de, updated_at=NOW()
                 WHERE id=:id'
            );
            $stmt->execute([
                'slug'    => $slug,
                'name'    => $name,
                'desc'    => $description ?: null,
                'case_id' => $caseId,
                'is_live' => $isLive,
                'slow'    => $slowMode,
                'mo'      => $membersOnly,
                'rp'      => $reactionPolicy,
                'te'      => $triviaEnabled,
                'de'      => $donationsEnabled,
                'id'      => $id,
            ]);
            redirect('/admin/chat-rooms.php', 'Room updated.', 'success');
        }
    }

    // ── Toggle archive ──────────────────────────────────────────────────────
    if ($action === 'toggle_archive') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE chat_rooms SET is_archived = NOT is_archived, updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
        redirect('/admin/chat-rooms.php', 'Room updated.', 'success');
    }
}

// ── Load data ──────────────────────────────────────────────────────────────────
$rooms  = [];
$cases  = [];
$editRoom = null;

if ($pdo) {
    $rooms = $pdo->query(
        'SELECT r.*, c.title AS case_title
           FROM chat_rooms r
           LEFT JOIN cases c ON c.id = r.case_id
           ORDER BY r.is_archived ASC, r.created_at DESC'
    )->fetchAll();

    $cases = $pdo->query('SELECT id, title FROM cases WHERE status = "published" ORDER BY title ASC')->fetchAll();

    $editId = (int) ($_GET['edit'] ?? 0);
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM chat_rooms WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $editId]);
        $editRoom = $stmt->fetch() ?: null;
    }
}

$pageActions = '<a href="/admin/chat-rooms.php" class="btn btn-ptmd-primary btn-sm"><i class="fa-solid fa-plus me-1"></i>New Room</a>';

include __DIR__ . '/_admin_head.php';
?>

<!-- ── Room Form ──────────────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg mb-5">
    <h2 class="h5 mb-4"><?php echo $editRoom ? 'Edit Room' : 'New Room'; ?></h2>
    <form method="post" action="/admin/chat-rooms.php">
        <?php csrf_input(); ?>
        <input type="hidden" name="action" value="<?php echo $editRoom ? 'update' : 'create'; ?>">
        <?php if ($editRoom): ?>
            <input type="hidden" name="id" value="<?php ee($editRoom['id']); ?>">
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label" for="room_name">Name <span class="ptmd-text-red">*</span></label>
                <input type="text" class="form-control" id="room_name" name="name" required
                       value="<?php ee($editRoom['name'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="room_slug">Slug <span class="ptmd-text-red">*</span></label>
                <input type="text" class="form-control font-mono" id="room_slug" name="slug" required
                       placeholder="case-chat"
                       value="<?php ee($editRoom['slug'] ?? ''); ?>">
                <div class="form-text ptmd-muted small">Lowercase letters, numbers, and hyphens only.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="room_case">Linked Case</label>
                <select class="form-select" id="room_case" name="case_id">
                    <option value="">— none —</option>
                    <?php foreach ($cases as $ep): ?>
                        <option value="<?php ee($ep['id']); ?>"
                            <?php echo (int) ($editRoom['case_id'] ?? 0) === (int) $ep['id'] ? 'selected' : ''; ?>>
                            <?php ee($ep['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="room_desc">Description</label>
                <textarea class="form-control" id="room_desc" name="description" rows="2"><?php ee($editRoom['description'] ?? ''); ?></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="room_slow">Slow Mode (seconds)</label>
                <input type="number" class="form-control" id="room_slow" name="slow_mode_seconds"
                       min="0" value="<?php ee($editRoom['slow_mode_seconds'] ?? 0); ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end gap-4 pb-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="room_live" name="is_live" value="1"
                        <?php echo !empty($editRoom['is_live']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="room_live">Mark as Live</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="room_mo" name="members_only" value="1"
                        <?php echo !empty($editRoom['members_only']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="room_mo">Members Only</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="room_trivia" name="trivia_enabled" value="1"
                        <?php echo !empty($editRoom['trivia_enabled']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="room_trivia">Trivia</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="room_donations" name="donations_enabled" value="1"
                        <?php echo ($editRoom === null || !empty($editRoom['donations_enabled'])) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="room_donations">Donations</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="room_react_policy">Reaction Policy</label>
                <select class="form-select" id="room_react_policy" name="reaction_policy">
                    <option value="all"        <?php echo ($editRoom['reaction_policy'] ?? 'all') === 'all'        ? 'selected' : ''; ?>>All users</option>
                    <option value="registered" <?php echo ($editRoom['reaction_policy'] ?? '')    === 'registered' ? 'selected' : ''; ?>>Registered only</option>
                    <option value="disabled"   <?php echo ($editRoom['reaction_policy'] ?? '')    === 'disabled'   ? 'selected' : ''; ?>>Disabled</option>
                </select>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-ptmd-primary">
                <?php echo $editRoom ? 'Save Changes' : 'Create Room'; ?>
            </button>
            <?php if ($editRoom): ?>
                <a href="/admin/chat-rooms.php" class="btn btn-ptmd-ghost">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ── Rooms table ────────────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-0 overflow-hidden">
    <div class="px-lg py-md d-flex justify-content-between align-items-center"
         style="border-bottom:1px solid var(--ptmd-border)">
        <span class="fw-600">All Rooms</span>
        <span class="ptmd-muted small"><?php echo count($rooms); ?> room<?php echo count($rooms) !== 1 ? 's' : ''; ?></span>
    </div>

    <?php if (empty($rooms)): ?>
        <p class="ptmd-muted p-lg">No rooms yet. Create one above.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Name / Slug</th>
                        <th>Linked Case</th>
                        <th>Status</th>
                        <th>Slow Mode</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $room): ?>
                        <tr class="<?php echo $room['is_archived'] ? 'opacity-50' : ''; ?>">
                            <td>
                                <div class="fw-600"><?php ee($room['name']); ?></div>
                                <code class="ptmd-muted small"><?php ee($room['slug']); ?></code>
                            </td>
                            <td class="ptmd-muted small">
                                <?php ee($room['case_title'] ?? '—'); ?>
                            </td>
                            <td>
                                <?php if ($room['is_archived']): ?>
                                    <span class="ptmd-badge ptmd-badge-muted">Archived</span>
                                <?php elseif ($room['is_live']): ?>
                                    <span class="ptmd-live-badge"><span class="live-dot"></span>LIVE</span>
                                <?php else: ?>
                                    <span class="ptmd-badge ptmd-badge-teal">Active</span>
                                <?php endif; ?>
                                <?php if ($room['members_only']): ?>
                                    <span class="ptmd-badge ptmd-badge-gold ms-1">Members Only</span>
                                <?php endif; ?>
                            </td>
                            <td class="ptmd-muted small">
                                <?php echo $room['slow_mode_seconds'] > 0 ? (int) $room['slow_mode_seconds'] . 's' : '—'; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="/admin/chat-rooms.php?edit=<?php ee($room['id']); ?>"
                                       class="btn btn-ptmd-ghost btn-sm"
                                       data-tippy-content="Edit room">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <form method="post" action="/admin/chat-rooms.php" class="d-inline">
                                        <?php csrf_input(); ?>
                                        <input type="hidden" name="action" value="toggle_archive">
                                        <input type="hidden" name="id" value="<?php ee($room['id']); ?>">
                                        <button type="submit" class="btn btn-ptmd-ghost btn-sm"
                                                data-tippy-content="<?php echo $room['is_archived'] ? 'Unarchive' : 'Archive'; ?>">
                                            <i class="fa-solid <?php echo $room['is_archived'] ? 'fa-box-open' : 'fa-box-archive'; ?>"></i>
                                        </button>
                                    </form>
                                    <a href="/admin/chat.php?room=<?php ee($room['id']); ?>"
                                       class="btn btn-ptmd-ghost btn-sm"
                                       data-tippy-content="Moderate messages">
                                        <i class="fa-solid fa-comments"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>
