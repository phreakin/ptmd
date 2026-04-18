<?php
/** @var array<int,array{href:string,label:string,icon:string,active?:bool}> $uiNav */
$uiNav = $uiNav ?? [
    ['href' => '#', 'label' => 'Dashboard', 'icon' => 'fa-gauge', 'active' => true],
    ['href' => '#', 'label' => 'Case List', 'icon' => 'fa-list-check'],
    ['href' => '#', 'label' => 'Hook Lab', 'icon' => 'fa-bolt'],
    ['href' => '#', 'label' => 'Queue Board', 'icon' => 'fa-calendar-check'],
    ['href' => '#', 'label' => 'Analytics', 'icon' => 'fa-chart-line'],
];
?>
<div class="ptmd-page-shell">
    <header class="glass-toolbar d-flex align-items-center justify-content-between px-3 py-2">
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-ptmd-ghost btn-sm" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fa-solid fa-bars"></i></button>
            <strong class="ptmd-kicker mb-0">Paper Trail MD Control Room</strong>
        </div>
        <a href="#" class="ptmd-topbar-command" data-ptmd-command-open>
            <i class="fa-solid fa-magnifying-glass"></i>
            <span>Search cases, queue, assets, settings</span>
            <kbd>⌘K</kbd>
        </a>
    </header>

    <aside class="glass-sidebar p-3">
        <nav class="d-grid gap-2">
            <?php foreach ($uiNav as $item): ?>
                <a class="ptmd-nav-item <?php echo !empty($item['active']) ? 'active' : ''; ?>" href="<?php ee($item['href']); ?>">
                    <span class="nav-icon"><i class="fa-solid <?php ee($item['icon']); ?>"></i></span>
                    <?php ee($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>
</div>
