FROM php:8.3-fpm

# ── System dependencies + PHP extensions (single layer) ──────────────────────
# fonts-liberation is what OgImage draws the social card with: GD needs a font
# file and the pages' `system-ui` is not one. Liberation Sans rather than DejaVu
# because it is the neo-grotesque `system-ui` resolves to for most readers — see
# OgImage::SYSTEM_FONTS. Without a font installed, every build logs
# "[OgImage] No system sans font found" and quietly ships no cards.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libonig-dev \
        libicu-dev \
        libfreetype6-dev \
        libpng-dev \
        fonts-liberation \
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
