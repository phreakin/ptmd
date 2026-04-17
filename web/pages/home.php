<?php
/**
 * PTMD — Home page
 * Layout is loaded from site_settings.home_module_layout.
 */

$featuredEpisode = get_featured_episode();
$latestEpisodes  = get_latest_episodes(6);

$moduleFiles = [
    'hero'     => __DIR__ . '/home-modules/hero.php',
    'featured' => __DIR__ . '/home-modules/featured.php',
    'latest'   => __DIR__ . '/home-modules/latest.php',
    'social'   => __DIR__ . '/home-modules/social.php',
];
$defaultModules = array_keys($moduleFiles);
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
    if (!isset($moduleFiles[$moduleId])) {
        continue;
    }
    include $moduleFiles[$moduleId];
}
?>
