FROM php:8.2-apache

# Enable Apache mod_rewrite for clean URLs
RUN a2enmod rewrite

# Install PHP extensions required for PDO and MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# ext-zip (required by vendor/openspout/openspout to read/write XLSX workbooks
# for the K2 Drive automation import queue) needs libzip-dev at build time, and
# its runtime shared library (libzip5) must stay installed — the compiled zip.so
# links against it, so it cannot be purged afterward like a typical -dev package.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Configure custom PHP limits for large uploads.
#
# post_max_size caps the WHOLE multipart request, not one file. The K2 AON
# endpoint posts the catalog and price workbooks together, so its worst case is
# 2 x K2_AUTOMATION_MAX_UPLOAD_BYTES (64M default) plus multipart overhead —
# 140.8M by check_k2_automation_readiness.php's own calculation. The previous
# 64M silently truncated that request; 192M clears it with headroom.
# upload_max_filesize stays a PER-FILE cap and only needs to cover one workbook;
# 80M gives the ~10% headroom the readiness checker recommends over the 64M
# application maximum. memory_limit does NOT need to track post_max_size — PHP
# streams uploads to temporary files rather than buffering them in memory — but
# it does have to cover the manager dashboard, which is what actually sets this
# number. get_dashboard_rows() (includes/dashboard_data_helper.php) loads every
# persona-scoped PR line into PHP arrays in one pass and then computes Smart
# Status, AON classification and SLA verdicts across the whole set. Measured
# 2026-08-03 at ~45,000 rows: 302 MB peak, 3.3 s. The previous 256M was breached
# by that and killed the dashboard's AJAX endpoints mid-response (HTTP 200 with a
# truncated, unparseable body, so the page span forever on "Loading...").
# 512M restores the ~40% headroom 256M used to give and covers roughly 75,000
# rows at the measured ~6.7 KB/row. Past that the fix is to aggregate in SQL
# instead of raising this again.
RUN echo "upload_max_filesize = 80M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 192M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini

# Session cookie hardening.
#
# cookie_domain scopes the cookie to the parent domain so the app shares one
# login with its sibling subdomains (phpMyAdmin behind db.<domain>, gated by
# workspace/pages/auth_pma.php). The cost: every subdomain can then write that
# cookie, so a weakness on any one of them can plant a session ID on a victim.
# The app-side defence is session_regenerate_id(true) in both login handlers;
# the flags below are the transport side.
#
#   cookie_secure   — HTTPS only. Overridden in dev (docker/php-dev.ini),
#                     otherwise http://localhost:8080 never keeps a session.
#   cookie_samesite — Lax, not Strict: deep-links from email must still arrive
#                     logged in, which return-to-intended depends on.
#   use_strict_mode — refuse a session ID the server never issued; without it
#                     the fixation attack above is trivial.
#   gc_maxlifetime  — must be >= the largest WS_IDLE_LIMIT_* in
#                     workspace/includes/auth.php (Viewer: 7200). PHP's default
#                     of 1440 deletes the session file first, so the idle limits
#                     that file enforces never get to speak.
RUN { \
      echo "session.cookie_domain = '.vssportal.online'"; \
      echo "session.cookie_secure = 1"; \
      echo "session.cookie_httponly = 1"; \
      echo "session.cookie_samesite = 'Lax'"; \
      echo "session.use_strict_mode = 1"; \
      echo "session.gc_maxlifetime = 7200"; \
    } > /usr/local/etc/php/conf.d/session.ini

# Production-safe error handling (baked into the image, so it is the default for
# EVERY build). display_errors=Off keeps PHP notices/warnings/deprecations out of
# the HTTP response — otherwise they render into pages for end users and, when
# emitted before a header() call, cause "headers already sent" (see commit
# 699066a). Errors are not lost: log_errors=On sends them to the SAPI default
# target (container stderr, captured by `docker logs`). Local development
# re-enables on-screen errors by mounting docker/php-dev.ini over conf.d (see
# docker-compose.yml); docker-compose.prod.yml does NOT, so production stays Off.
RUN { \
      echo "display_errors = Off"; \
      echo "display_startup_errors = Off"; \
      echo "log_errors = On"; \
    } > /usr/local/etc/php/conf.d/error-handling.ini

# Set the working directory
WORKDIR /var/www/html

# Copy the application code into the container
COPY . /var/www/html/

# Adjust permissions so Apache can read/write
RUN chown -R www-data:www-data /var/www/html

# K2 Drive automation private state root, mounted as the k2_private volume in
# docker-compose.prod.yml. Created here rather than left to the volume mount
# because Docker seeds a new named volume from the image path's contents AND
# ownership: without this the volume would come up root-owned and Apache
# (www-data) could not stage uploaded workbooks into it. Kept off /var/www on
# purpose — K2AutomationFileStorageConfig rejects any storage root containing a
# public-web path segment ("www", "public_html", "htdocs", "wwwroot").
RUN mkdir -p /var/k2_automation/storage /var/k2_automation/logs \
    && chown -R www-data:www-data /var/k2_automation

# Nightly VSS backup receiver storage (backups/receive_backup.php). Off
# /var/www for the same reason as k2_automation above; mounted as the
# vss_backups volume in docker-compose.prod.yml so it survives redeploys.
RUN mkdir -p /var/vss_backups/storage \
    && chown -R www-data:www-data /var/vss_backups
