#!/bin/bash
set -e

main() {
    rm -f /var/run/apache2/apache2.pid 2>/dev/null || true

    # 1. Create WP Config
    if [[ ! -f wp-config.php ]]; then
        wp config create \
            --dbhost="${DB_HOST:-mysql}:${DB_PORT:-3306}" \
            --dbname="${DB_NAME:-flux_db}" \
            --dbuser="${DB_USER:-flux_root}" \
            --dbpass="${DB_PASSWORD:-root}" \
            --dbprefix="${DB_PREFIX:-flx_}" \
            --extra-php <<PHP
if ( isset( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) {
    \$_SERVER['HTTPS'] = 'on';
}
define( 'WP_DEBUG', ${WP_DEBUG:-true} );
define( 'WP_DEBUG_LOG', ${WP_DEBUG_LOG:-true} );
define( 'WP_DEBUG_DISPLAY', ${WP_DEBUG_DISPLAY:-false} );
define( 'DISABLE_WP_CRON', ${DISABLE_WP_CRON:-true} );
${EXTRA_PHP:-}
PHP
    fi

    # 2. Install Wordpress
    if ! wp core is-installed 2>/dev/null; then
        echo "Installing WordPress..."
        wp core install \
            --url="${WORDPRESS_WEBSITE_URL:-http://localhost:8000}" \
            --title="${WORDPRESS_WEBSITE_TITLE:-Flux}" \
            --admin_user="${WORDPRESS_ADMIN_USER:-flux}" \
            --admin_password="${WORDPRESS_ADMIN_PASSWORD:-flux}" \
            --admin_email="${WORDPRESS_ADMIN_EMAIL:-info@flux.be}" \
            --skip-email

        # Setup Wordpress
        wp post list --post_type=post --format=ids | xargs -r wp post delete --force 2>/dev/null || true
        wp post list --post_type=page --format=ids | xargs -r wp post delete --force 2>/dev/null || true
        wp post create --post_type=page --post_title='Flux' --post_status=publish --post_content='[flux_my_simple_shortcode]'
        wp option update show_on_front page
        wp option update page_on_front "$(wp post list --post_type=page --post_status=publish --posts_per_page=1 --field=ID)"

        wp option update time_format "H:i"
        wp option update date_format "d/m/Y"
        wp option update timezone_string "Europe/Brussels"
        wp option update start_of_week 1
        wp option update blogdescription ""
        wp option update comment_registration 1
        wp option update uploads_use_yearmonth_folders 1

        wp theme delete twentytwenty twentytwentyone 2>/dev/null || true
        wp rewrite structure "${WORDPRESS_PERMALINK_STRUCTURE:-/%postname%/}" --hard
    fi

    # 3. Active Themes & Plugins
    local parent="${PARENT_THEME:-page-builder-framework}"
    local child="${CHILD_THEME:-flux-child}"

    if [[ -n "$child" ]] && wp theme is-installed "$child" 2>/dev/null; then
        wp theme activate "$child" 2>/dev/null || true
    elif [[ -n "$parent" ]] && wp theme is-installed "$parent" 2>/dev/null; then
        wp theme activate "$parent" 2>/dev/null || true
    fi

    wp plugin activate --all 2>/dev/null || true

    echo "WordPress setup complete"
    exec "$@"
}

main "$@"