<?php
/**
 * iHymns — HTML <head> Component
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 *
 * PURPOSE:
 * Reusable <head> section containing meta tags, stylesheets, and PWA config.
 * Include via: require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'head.php';
 *
 * REQUIRES: $app array from infoAppVer.php to be loaded before inclusion.
 *
 * @requires PHP 8.5+
 */

/* Prevent direct access */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access not allowed.');
}
?>
    <!-- ================================================================
         META TAGS
         ================================================================ -->

    <!-- Character encoding: UTF-8 supports all international characters in lyrics -->
    <meta charset="UTF-8">

    <!-- Viewport: ensures proper responsive behaviour on mobile devices -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Page title: shown in browser tab, bookmarks, and search results -->
    <title><?php echo htmlspecialchars($app["Application"]["Name"]); ?> — Christian Lyrics for Worship</title>

    <!-- Description: used by search engines and social media previews -->
    <meta name="description" content="<?php echo htmlspecialchars($app["Application"]["Description"]["Synopsis"]); ?>">

    <!-- Theme colour: slate for browser address bar -->
    <meta name="theme-color" content="#1e293b" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">

    <!-- Apple-specific meta tags for PWA/home screen behaviour -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($app["Application"]["Name"]); ?>">

    <!-- PWA Web App Manifest: defines app name, icons, theme, display mode -->
    <link rel="manifest" href="manifest.json">

    <!-- Favicon: shown in browser tabs and bookmarks -->
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">

    <!-- Apple touch icon: shown on iOS home screen when app is added -->
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png">

    <!-- ================================================================
         STYLESHEETS
         ================================================================ -->

    <!-- Bootstrap 5.3 CSS: the responsive UI framework -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">

    <!-- Bootstrap Icons 1.11: icon font for UI elements -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">

    <!-- Animate.css 4.1: CSS animation library for smooth transitions -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"
          rel="stylesheet">

    <!-- iHymns custom stylesheet: app-specific styles, overrides, and theming -->
    <link href="css/styles.css" rel="stylesheet">

    <!-- Print stylesheet: optimised layout for printing song lyrics -->
    <link href="css/print.css" rel="stylesheet" media="print">
