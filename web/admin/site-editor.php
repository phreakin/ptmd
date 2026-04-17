<?php
/**
 * PTMD Admin — Site Editor
 * Edit homepage module visibility and order.
 */

$pageTitle      = 'Site Editor | PTMD Admin';
$activePage     = 'site-editor';
$pageHeading    = 'Site Editor';
$pageSubheading = 'Edit homepage layout with click and drag controls.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

$moduleCatalog = [
    'hero'     => ['label' => 'Hero',             'icon' => 'fa-star',        'description' => 'Top section with headline and CTA'],
    'featured' => ['label' => 'Featured case', 'icon' => 'fa-film',        'description' => 'Highlighted latest case'],
    'latest'   => ['label' => 'Latest cases',  'icon' => 'fa-table-cells', 'description' => 'case cards grid'],
    'social'   => ['label' => 'Social CTA',       'icon' => 'fa-share-nodes', 'description' => 'Follow links and call-to-action'],
];

$defaultOrder = array_keys($moduleCatalog);
$currentOrder = $defaultOrder;

if ($pdo) {
    $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute(['key' => 'home_module_layout']);
    $saved = $stmt->fetchColumn();

    if (is_string($saved) && $saved !== '') {
        $decoded = json_decode($saved, true);
        if (is_array($decoded)) {
            $ordered = [];
            foreach ($decoded as $moduleId) {
                if (!is_string($moduleId) || !isset($moduleCatalog[$moduleId])) {
                    continue;
                }
                if (!in_array($moduleId, $ordered, true)) {
                    $ordered[] = $moduleId;
                }
            }
            if ($ordered) {
                $currentOrder = $ordered;
            }
        }
    }
}

if (is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/site-editor.php', 'Invalid CSRF token.', 'danger');
    }

    if (!$pdo) {
        redirect('/admin/site-editor.php', 'Database unavailable.', 'danger');
    }

    $enabledModules = array_filter(
        array_map('strval', (array) ($_POST['enabled_modules'] ?? [])),
        static fn ($id) => isset($moduleCatalog[$id])
    );
    $enabledModules = array_values(array_unique($enabledModules));

    $orderRaw = trim((string) ($_POST['module_order'] ?? ''));
    $orderIds = $orderRaw === '' ? [] : explode(',', $orderRaw);

    $finalOrder = [];
    foreach ($orderIds as $id) {
        $id = trim($id);
        if ($id === '' || !isset($moduleCatalog[$id]) || !in_array($id, $enabledModules, true)) {
            continue;
        }
        if (!in_array($id, $finalOrder, true)) {
            $finalOrder[] = $id;
        }
    }

    foreach ($enabledModules as $id) {
        if (!in_array($id, $finalOrder, true)) {
            $finalOrder[] = $id;
        }
    }

    if (!$finalOrder) {
        $finalOrder = $defaultOrder;
    }

    $json = json_encode($finalOrder, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        redirect('/admin/site-editor.php', 'Could not encode layout.', 'danger');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value, setting_type, label, group_name, updated_at)
         VALUES (:key, :value, "json", :label, :group_name, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $stmt->execute([
        'key'        => 'home_module_layout',
        'value'      => $json,
        'label'      => 'Homepage Module Layout',
        'group_name' => 'homepage',
    ]);

    redirect('/admin/site-editor.php', 'Homepage layout updated.', 'success');
}

$enabledMap = array_fill_keys($currentOrder, true);
?>

<form method="post" action="/admin/site-editor.php">
    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
    <input type="hidden" name="module_order" id="moduleOrderInput" value="<?php ee(implode(',', $currentOrder)); ?>">

    <div class="ptmd-panel p-xl">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h2 class="h5 mb-1">Homepage Modules</h2>
                <p class="ptmd-muted small mb-0">Drag to reorder, click arrows to nudge, and toggle what appears on the page.</p>
            </div>
            <a href="/index.php?page=home" target="_blank" rel="noopener" class="btn btn-ptmd-ghost btn-sm">
                <i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Preview Homepage
            </a>
        </div>

        <ul id="siteModuleList" class="list-unstyled d-flex flex-column gap-3 mb-4">
            <?php foreach ($currentOrder as $moduleId): ?>
                <?php $module = $moduleCatalog[$moduleId] ?? null; ?>
                <?php if (!$module) continue; ?>
                <li class="ptmd-module-item" draggable="true" data-module-id="<?php ee($moduleId); ?>">
                    <div class="d-flex align-items-start gap-3 flex-grow-1">
                        <button type="button" class="btn btn-ptmd-ghost btn-sm module-drag-handle" title="Drag module">
                            <i class="fa-solid fa-grip-vertical"></i>
                        </button>
                        <div class="form-check form-switch mt-1">
                            <input
                                class="form-check-input module-toggle"
                                type="checkbox"
                                id="mod_<?php ee($moduleId); ?>"
                                name="enabled_modules[]"
                                value="<?php ee($moduleId); ?>"
                                <?php echo !empty($enabledMap[$moduleId]) ? 'checked' : ''; ?>
                            >
                        </div>
                        <label class="flex-grow-1" for="mod_<?php ee($moduleId); ?>">
                            <span class="d-block fw-600">
                                <i class="fa-solid <?php ee($module['icon']); ?> me-2 ptmd-text-teal"></i>
                                <?php ee($module['label']); ?>
                            </span>
                            <span class="ptmd-muted small"><?php ee($module['description']); ?></span>
                        </label>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-ptmd-ghost btn-sm module-up" title="Move up">
                            <i class="fa-solid fa-arrow-up"></i>
                        </button>
                        <button type="button" class="btn btn-ptmd-ghost btn-sm module-down" title="Move down">
                            <i class="fa-solid fa-arrow-down"></i>
                        </button>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="d-flex gap-3">
            <button class="btn btn-ptmd-primary" type="submit">
                <i class="fa-solid fa-floppy-disk me-2"></i>Save Layout
            </button>
        </div>
    </div>
</form>

<?php
$extraScripts = <<<HTML
<style>
    .ptmd-module-item {
        background: var(--ptmd-surface-2);
        border: 1px solid var(--ptmd-border);
        border-radius: var(--radius-lg);
        padding: .9rem 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        cursor: move;
    }
    .ptmd-module-item.dragging { opacity: .55; border-color: var(--ptmd-teal); }
    .ptmd-module-item.is-disabled { opacity: .55; }
    .module-drag-handle { cursor: grab; }
</style>
<script>
(() => {
    const list = document.getElementById('siteModuleList');
    const orderInput = document.getElementById('moduleOrderInput');
    if (!list || !orderInput) return;

    function updateOrderInput() {
        const order = [...list.querySelectorAll('[data-module-id]')]
            .map(el => el.dataset.moduleId)
            .filter(Boolean);
        orderInput.value = order.join(',');
    }

    function syncDisabledState() {
        list.querySelectorAll('.ptmd-module-item').forEach((item) => {
            const checked = item.querySelector('.module-toggle')?.checked;
            item.classList.toggle('is-disabled', !checked);
        });
    }

    let draggingItem = null;

    list.addEventListener('dragstart', (e) => {
        const item = e.target.closest('.ptmd-module-item');
        if (!item) return;
        draggingItem = item;
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    list.addEventListener('dragend', () => {
        if (draggingItem) draggingItem.classList.remove('dragging');
        draggingItem = null;
        updateOrderInput();
    });

    list.addEventListener('dragover', (e) => {
        e.preventDefault();
        const overItem = e.target.closest('.ptmd-module-item');
        if (!draggingItem || !overItem || overItem === draggingItem) return;

        const rect = overItem.getBoundingClientRect();
        const after = e.clientY > rect.top + (rect.height / 2);
        if (after) {
            overItem.after(draggingItem);
        } else {
            overItem.before(draggingItem);
        }
    });

    list.addEventListener('click', (e) => {
        const upBtn = e.target.closest('.module-up');
        const downBtn = e.target.closest('.module-down');
        if (!upBtn && !downBtn) return;

        const item = e.target.closest('.ptmd-module-item');
        if (!item) return;

        if (upBtn && item.previousElementSibling) {
            item.previousElementSibling.before(item);
            updateOrderInput();
        }

        if (downBtn && item.nextElementSibling) {
            item.nextElementSibling.after(item);
            updateOrderInput();
        }
    });

    list.addEventListener('change', (e) => {
        if (!e.target.closest('.module-toggle')) return;
        syncDisabledState();
    });

    updateOrderInput();
    syncDisabledState();
})();
</script>
HTML;

include __DIR__ . '/_admin_footer.php';
?>
