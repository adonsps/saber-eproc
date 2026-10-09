<?php
// includes/upload_paths.php
//
// Single source of truth for where vendor upload files live on disk.
//
// There are two historical bases, and which one is correct depends on the
// deployment:
//
//   <root>/uploads      The persistent volume mount in Docker
//                       (docker-compose.prod.yml mounts app_uploads at
//                       /var/www/html/uploads). Inside the docroot, so the
//                       browser's ../uploads/<path> links resolve to it, and
//                       inside the nightly backup, which targets uploads/.
//
//   <root>/../uploads   The pre-Docker shared-hosting layout, where uploads sat
//                       beside public_html. In the container this resolves to
//                       /var/www, which is root-owned and NOT writable by
//                       www-data — and has no uploads directory at all. Writes
//                       aimed here fail with "mkdir(): Permission denied", and
//                       because callers historically ignored the return value of
//                       move_uploaded_file(), the upload was silently discarded
//                       while the UI reported success. See commit 699066a.
//
// So: READ from both bases, so files stored under either scheme keep resolving
// forever; WRITE only to a base that actually exists and is writable, preferring
// <root>/uploads. This mirrors the inline blocks in profile_action.php (699066a),
// approve_action.php, all_vendors.php and download_vendor_docs.php, which this
// file exists to replace.
//
// Deliberately NOT solved by chown-ing /var/www or mounting a volume there:
// that would make writes succeed while leaving files outside the docroot (links
// 404) and outside the backup.

if (!function_exists('vendor_upload_bases')) {

    /**
     * Application root — this file lives at <root>/includes/, so one level up.
     * Under Docker that is /var/www/html.
     */
    function vendor_upload_root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Every upload base that actually exists, most-preferred first, de-duplicated
     * by real path (the two candidates can collapse to the same directory in dev,
     * where docker-compose.yml bind-mounts ./uploads at both locations).
     *
     * @return string[] Absolute paths, no trailing separator.
     */
    function vendor_upload_bases(): array
    {
        $root  = vendor_upload_root();
        $bases = [];
        foreach ([$root . '/uploads', $root . '/../uploads'] as $candidate) {
            $real = realpath($candidate);
            if ($real && is_dir($real)) {
                $bases[$real] = true;
            }
        }
        return array_keys($bases);
    }

    /**
     * The base new files should be written into: the first existing base that is
     * actually writable, preferring <root>/uploads.
     *
     * @return string|null Absolute path, or null when nothing is writable — in
     *                     which case the caller MUST report an error rather than
     *                     pretending the upload worked.
     */
    function vendor_upload_write_base(): ?string
    {
        foreach (vendor_upload_bases() as $base) {
            if (is_writable($base)) {
                return $base;
            }
        }
        return null;
    }

    /**
     * Resolve a stored relative path ("vendor_1_Foo/NPWP_123.pdf") to an absolute
     * path, probing both bases so records written under either scheme still open.
     *
     * Keeps the traversal guard from profile_action.php: the resolved real path
     * must still sit under the base it was found in, so a stored "../../etc/passwd"
     * cannot escape the uploads tree.
     *
     * @return string|null Absolute path of an existing file, or null.
     */
    function vendor_upload_resolve(?string $stored): ?string
    {
        if (empty($stored)) {
            return null;
        }
        foreach (vendor_upload_bases() as $base) {
            $path = realpath($base . DIRECTORY_SEPARATOR . $stored);
            if ($path && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Absolute path of the vendor's own upload folder, creating it when needed.
     *
     * Reuses an existing vendor_<id>_* folder wherever it already lives (so a
     * vendor onboarded under the old scheme keeps one folder rather than growing
     * a second), and only falls back to creating a new one under the writable
     * base. Mode 0775 rather than the historical 0777 — www-data owns the tree,
     * so world-write buys nothing.
     *
     * @param  bool|null $created Set by reference to true when a folder was made.
     * @return string|null Absolute path without a trailing separator, or null if
     *                     no base is writable (caller must surface an error).
     */
    function vendor_upload_dir_for(int $vendor_id, string $company_name, ?bool &$created = null): ?string
    {
        $created = false;

        // 1. An existing folder wins, but only if we can actually write into it.
        foreach (vendor_upload_bases() as $base) {
            $existing = glob($base . '/vendor_' . $vendor_id . '_*', GLOB_ONLYDIR);
            if ($existing && count($existing) > 0 && is_writable($existing[0])) {
                return rtrim($existing[0], "/\\");
            }
        }

        // 2. Otherwise create one under the writable base.
        $write_base = vendor_upload_write_base();
        if ($write_base === null) {
            return null;
        }

        $safe_name = preg_replace('/[^A-Za-z0-9]/', '_', $company_name);
        $target    = $write_base . '/vendor_' . $vendor_id . '_' . $safe_name;

        if (!is_dir($target)) {
            // The is_dir() re-check absorbs the race where a concurrent request
            // created the same folder between the check and the mkdir.
            if (!@mkdir($target, 0775, true) && !is_dir($target)) {
                return null;
            }
            $created = true;
        }

        return $target;
    }

    /**
     * The path to store in the database for a file inside a vendor folder:
     * "vendor_1_Foo/NPWP_123.pdf" — relative to whichever base it lives under,
     * which is exactly what vendor_upload_resolve() and the browser's
     * ../uploads/<path> links expect.
     *
     * Accepts the directory with or without a trailing separator, and trims both
     * styles rather than only DIRECTORY_SEPARATOR — callers build these paths by
     * string concatenation, so a "/"-terminated path can reach this on any host.
     */
    function vendor_upload_relative_path(string $vendor_dir_abs, string $filename): string
    {
        return basename(rtrim($vendor_dir_abs, "/\\")) . '/' . $filename;
    }
}
