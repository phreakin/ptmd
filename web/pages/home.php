<?php
/**
 * PTMD — Home page
 * Layout is loaded from site_settings.home_module_layout.
 */

$featuredEpisode = get_featured_episode();
$latestEpisodes  = get_latest_episodes(6);

$defaultModules = ['hero', 'featured', 'latest', 'social'];
$modules = $defaultModules;

$savedLayout = site_setting('home_module_layout', '');
if ($savedLayout !== '') {
    $decoded = json_decode($savedLayout, true);
    if (is_array($decoded)) {
        $ordered = [];
        foreach ($decoded as $moduleId) {
            if (!is_string($moduleId) || !in_array($moduleId, $defaultModules, true)) {
                continue;
            }
            if (!in_array($moduleId, $ordered, true)) {
                $ordered[] = $moduleId;
            }
        }
        if ($ordered) {
            $modules = $ordered;
        }
    }
}

foreach ($modules as $moduleId) {
    include __DIR__ . '/home-modules/' . $moduleId . '.php';
}
?>
