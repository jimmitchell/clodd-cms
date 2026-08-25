FROM php:8.3-fpm

# ── System dependencies + PHP extensions (single layer) ──────────────────────
# fonts-urw-base35 is a safety net, not the card's font: OgImage draws with the
# Nimbus Sans pinned in fonts/og/, and this package is the same face for the case
# where that pin has gone missing. Cheap insurance — GD needs a real font file
# and the pages' `system-ui` is not one, so a fontless image draws no cards at
# all and only says so on stderr.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libonig-dev \
        libicu-dev \
        libfreetype6-dev \
        libpng-dev \
        fonts-urw-base35 \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype \
    && docker-php-ext-install pdo_sqlite mbstring intl gd \
    && docker-php-ext-enable fileinfo || true

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── PHP ini (development overrides) ──────────────────────────────────────────
COPY docker/php.ini $PHP_INI_DIR/conf.d/cms.ini

# ── Entrypoint ────────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/cms

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
