<?php
/**
 * PTMD — Shared <head> partial
 * Included before opening <body> from every public and admin page.
 *
 * Expects $pageTitle to be set by the caller.
 */
$siteName = site_setting('site_name', 'Paper Trail MD');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php ee($pageTitle ?? $siteName); ?></title>
    <meta name="description" content="<?php ee(site_setting('site_description', 'Investigative documentary media from ' . $siteName)); ?>">

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Font Awesome Free -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@latest/css/all.min.css">
    <!-- Tippy.js -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tippy.js@latest/dist/tippy.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@latest/dist/sweetalert2.min.css">

    <!-- PTMD Design System -->
    <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body>
<div class="ptmd-shell">
