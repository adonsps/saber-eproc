<?php
// includes/branding.php
//
// Single source of truth for the VSS logo and favicon.
//
// Before this file existed the logo was hardcoded in ~23 places across 10+
// files, using three different path conventions ($path_to_root, literal
// "../../", and a hardcoded "/vms/"). Swapping the logo meant editing every
// one of them, and the "/vms/" copies silently broke whenever the app was
// served from a different base path.
//
// Usage:
//     require_once __DIR__ . '/branding.php';   // adjust depth per file
//     echo vss_favicon_tags();                  // inside <head>
//     echo vss_brand_logo();                    // inside a navbar brand
//
// NB: never write a PHP closing tag in these comments. PHP honours it even
// inside a "//" comment, which would end the file right here.
//
// To change the logo or favicon site-wide, edit vss_favicon_tags() /
// vss_brand_logo() here — nothing else.

if (!function_exists('vss_asset')) {

    /**
     * Absolute filesystem path of the application root (the directory that
     * holds assets/, modules/, workspace/, ...).
     */
    function vss_app_root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__));
    }

    /**
     * URL prefix from the currently-running page up to the application root,
     * e.g. "" for /login.php and "../../" for /modules/vendor/dashboard.php.
     *
     * This mirrors the existing $path_to_root convention but derives it from
     * the running script instead of trusting each page to declare it, so it
     * stays correct whether the app is served from / or from /vms/, and on
     * the pages that never set $path_to_root at all.
     */
    function vss_path_to_root(): string
    {
        static $prefix = null;
        if ($prefix !== null) {
            return $prefix;
        }

        $app_root = vss_app_root();
        $script   = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $page_dir = $script !== '' ? str_replace('\\', '/', dirname(realpath($script) ?: $script)) : '';

        if ($page_dir !== '' && strpos($page_dir . '/', $app_root . '/') === 0) {
            $relative = trim(substr($page_dir, strlen($app_root)), '/');
            $depth    = $relative === '' ? 0 : substr_count($relative, '/') + 1;
            $prefix   = str_repeat('../', $depth);
        } else {
            // The running script sits outside the app tree (CLI, the automation
            // router harness, an unusual alias). Fall back to a root-relative
            // URL built from DOCUMENT_ROOT; "/" if even that is unavailable.
            $doc_root = isset($_SERVER['DOCUMENT_ROOT'])
                ? str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT']))
                : '';
            $prefix = ($doc_root !== '' && strpos($app_root . '/', $doc_root . '/') === 0)
                ? rtrim(substr($app_root, strlen($doc_root)), '/') . '/'
                : '/';
        }

        return $prefix;
    }

    /**
     * URL for a file under the app root, with a cache-busting version stamp.
     *
     * The stamp is the file's mtime, not time(): browsers keep the asset
     * cached until it actually changes, then pick up the new one immediately.
     */
    function vss_asset(string $relative_path): string
    {
        $relative_path = ltrim($relative_path, '/');
        $url = vss_path_to_root() . $relative_path;
        $mtime = @filemtime(vss_app_root() . '/' . $relative_path);

        return $mtime ? $url . '?v=' . $mtime : $url;
    }

    /**
     * The <link> tags for the browser tab / home-screen icon.
     * Emitted inside <head>; indentation matches a 4-space <head> block.
     */
    function vss_favicon_tags(): string
    {
        $svg = htmlspecialchars(vss_asset('assets/img/saber-emblem.svg'), ENT_QUOTES);
        $png = htmlspecialchars(vss_asset('assets/img/favicon.png'), ENT_QUOTES);
        $ico = htmlspecialchars(vss_asset('assets/img/favicon.ico'), ENT_QUOTES);
        $apple = htmlspecialchars(vss_asset('assets/img/apple-touch-icon.png'), ENT_QUOTES);

        return '<link rel="icon" type="image/svg+xml" href="' . $svg . '">' . "\n"
             . '    <link rel="icon" type="image/png" href="' . $png . '">' . "\n"
             . '    <link rel="shortcut icon" type="image/x-icon" href="' . $ico . '">' . "\n"
             . '    <link rel="apple-touch-icon" href="' . $apple . '">' . "\n";
    }

    /**
     * The logo <img> used as the brand mark in navbars and sidebars.
     *
     * @param int    $px           rendered size in pixels (square); default 36
     * @param string $extra_class  extra CSS classes for the caller's framework
     */
    function vss_brand_logo(int $px = 36, string $extra_class = ''): string
    {
        $src   = htmlspecialchars(vss_asset('assets/img/saber-emblem.svg'), ENT_QUOTES);
        $class = $extra_class !== ''
            ? ' class="' . htmlspecialchars($extra_class, ENT_QUOTES) . '"'
            : '';

        return '<img src="' . $src . '" alt="Saber eProc Logo"' . $class
             . ' style="width:' . $px . 'px;height:' . $px . 'px;object-fit:contain;">';
    }

    /**
     * Saber eProc horizontal logo with emblem and typography.
     *
     * @param int    $height       height in pixels
     * @param string $variant      'light' (for dark bg) or 'dark' (for light bg)
     * @param string $extra_class  extra CSS classes
     */
    function saber_brand_logo(int $height = 36, string $variant = 'dark', string $extra_class = ''): string
    {
        $file  = ($variant === 'light') ? 'assets/img/saber-eproc-logo-light.svg' : 'assets/img/saber-eproc-logo.svg';
        $src   = htmlspecialchars(vss_asset($file), ENT_QUOTES);
        $class = $extra_class !== ''
            ? ' class="' . htmlspecialchars($extra_class, ENT_QUOTES) . '"'
            : '';

        return '<img src="' . $src . '" alt="Saber eProc"' . $class
             . ' style="height:' . $height . 'px;width:auto;object-fit:contain;">';
    }
}

