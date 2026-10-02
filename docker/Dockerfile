ARG PHP_VERSION=8.4

FROM mlocati/php-extension-installer:2 AS php-extension-installer
FROM wordpress:cli AS wp-cli
FROM composer:2 AS wp-composer

FROM php:${PHP_VERSION}-apache AS base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# hadolint ignore=DL3008
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    echo "deb http://ftp.debian.org/debian $(sed -n 's/^VERSION=.*(\(.*\)).*/\1/p' /etc/os-release)-backports main" >> /etc/apt/sources.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        ghostscript less mariadb-client unzip zip \
    && install-php-extensions \
        bcmath \
        exif \
        gd \
        imagick \
        intl \
        mysqli \
        opcache \
        redis \
        soap \
        zip

COPY --from=wp-cli /usr/local/bin/wp /usr/local/bin/wp
COPY --from=wp-composer /usr/bin/composer /usr/local/bin/composer

COPY php/custom.ini /usr/local/etc/php/conf.d/custom.ini
COPY apache/fqdn.conf /etc/apache2/conf-available/fqdn.conf

COPY wp-cli.yml /var/www/html/wp-cli.yml

RUN php -m | grep -i zip

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && sed -i "s/Listen 80/Listen 8000/" /etc/apache2/ports.conf \
    && a2enconf fqdn \
    && a2enmod headers rewrite expires

COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

ARG WORDPRESS_VERSION=latest
ARG WORDPRESS_LOCALE=en_US

WORKDIR /var/www/html
RUN --mount=type=secret,id=acf_pro_key,mode=0444 \
    wp core download --version="${WORDPRESS_VERSION}" --locale="${WORDPRESS_LOCALE}" --skip-content --allow-root \
    && mkdir -p wp-content/themes wp-content/plugins \
    && curl -fsSL "https://downloads.wordpress.org/theme/twentytwentytwo.latest-stable.zip" -o /tmp/theme.zip \
    && unzip -q /tmp/theme.zip -d wp-content/themes \
    && rm /tmp/theme.zip \
    && for plugin in query-monitor options-view view-admin-as; do \
        curl -fsSL "https://downloads.wordpress.org/plugin/${plugin}.latest-stable.zip" -o /tmp/plugin.zip \
        && unzip -q /tmp/plugin.zip -d wp-content/plugins \
        && rm /tmp/plugin.zip; \
       done \
    && if [ -s /run/secrets/acf_pro_key ]; then \
        curl -fsSL "https://connect.advancedcustomfields.com/v2/plugins/download?p=pro&k=$(cat /run/secrets/acf_pro_key)" -o /tmp/acf.zip \
        && unzip -q /tmp/acf.zip -d wp-content/plugins \
        && rm /tmp/acf.zip; \
       fi

RUN useradd -ms /bin/bash admin \
    && chown -R admin:admin /var/www/html

ENV APACHE_RUN_USER=admin \
    APACHE_RUN_GROUP=admin

USER admin

EXPOSE 8000
ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["apache2-foreground"]

FROM base AS dev
USER root
# hadolint ignore=DL3008
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update \
    && apt-get install -y --no-install-recommends bash-completion vim \
    && pecl install xdebug-3.5.3 && docker-php-ext-enable xdebug
COPY php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini
RUN touch /tmp/xdebug.log && chmod 666 /tmp/xdebug.log
USER admin
